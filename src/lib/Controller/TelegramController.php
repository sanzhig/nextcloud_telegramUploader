<?php
// TelegramController.php
namespace OCA\TelegramUploader\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OC\Files\Filesystem;
use OCP\ILogger;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * @PublicPage
 */
class TelegramController extends Controller {
    private $logger;
    private $userManager;
    private $rootFolder;
    private $config;
    private $tele;
    private $urlGenerator;
    private $urlRoot;

    public function __construct(string $appName, IURLGenerator $urlGenerator, IRequest $request, ILogger $logger, IUserManager $userManager, IRootFolder $rootFolder, IConfig $config){
        parent::__construct($appName, $request);
        $this->logger = $logger;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
    }

    private function getUrlRoot() {
        $host = $_SERVER['HTTP_HOST'];
        $webroot = $this->config->getSystemValue('overwritewebroot', '');

        if (!empty($webroot)) {
            $webroot = '/' . trim($webroot, '/');
        }

        $urlToFile = "https://" . $host . $webroot . "/index.php/apps/files?dir=";

        return $urlToFile;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */

    public function webhook($message) {
        $this->urlRoot = self::getUrlRoot();
        $chatId = $message['chat']['id'];
        $msgId = $message['message_id'];

        $user = self::getUserByChatId($chatId);
        if (!$user) {
            return http_response_code(200); // Обработка случая, когда пользователь не найден
        }
        $userId = $user->getUID();

        // Получение конфигурации пользователя
        $bot_token = $this->config->getUserValue($userId, $this->appName, 'bot_token', '');
        $tgRootPath = $this->config->getUserValue($userId, $this->appName, 'tgRootPath', '');
        $isSeparateFolder = $this->config->getUserValue($userId, $this->appName, 'isSeparateFolder', false);
        $this->tele = new TelegramBot($bot_token);

        if ($this->validateMessage($message)) {
            if ($message['text'] === "/clear") {
                $this->handleClearCommand($chatId, $tgRootPath);
            } else {
                // Обработка сообщения с путем
                $this->handlePathMessage($chatId, $tgRootPath, $msgId, $message['text'], $userId);
            }
            return http_response_code(200);
        }

        // Обработка файлов
        $fileDetails = self::getFileDetails($message);
        $dataPath = $this->prepareDataPath($userId, $tgRootPath, $fileDetails, $isSeparateFolder);

        if ($fileDetails == null || $fileDetails['file_size'] > 20 * 1024 * 1024) {
            // Обработка ошибок файлов
            $this->handleFileErrors($chatId, $msgId, $fileDetails);
            return http_response_code(200);
        }

        $dataFolder = self::createFoldersRecursively($userId, $dataPath);
        $fileContent = $this->tele->getTelegramFile($fileDetails['file_id'], $chatId);
        if ($fileContent) {
            $file = $dataFolder->newFile($fileDetails['file_name']);
            $file->putContent($fileContent);
            $this->tele->message($chatId, "<a href='$this->urlRoot$dataPath'>" . $fileDetails['file_name'] . "</a> загружен", $msgId);
        } else {
            $this->tele->message($chatId, "<b>Файл не загружен.</b>", $msgId);
        }

        return http_response_code(200);
    }

    private function appendSlash($path) {
        return rtrim($path, '/') . '/';
    }

    private function validateMessage($message) {
        return isset($message['text']) && !isset($message['forward_from']) && !isset($message['photo'], $message['video'], $message['audio'], $message['document']);
    }

    private function handleClearCommand($chatId, $tgRootPath) {
        $this->tele->message($chatId, "Upload path: <pre>/" . trim($tgRootPath,"/") . "/</pre>");
    }

    private function handlePathMessage($chatId, $tgRootPath, $msgId, $text, $userId) {
        $this->config->setUserValue($userId, $this->appName, 'lastChangedTime', time());
        $this->config->setUserValue($userId, $this->appName, 'tmpFolder', $text);
        $this->tele->message($chatId, "Upload path: <pre>/" . trim($tgRootPath,'/') . "/".trim($text,'/') . "</pre>\n/clear - default path", $msgId);
    }

    private function prepareDataPath($userId, $tgRootPath, $fileDetails, $isSeparateFolder): string
    {
        $dataPath = $tgRootPath;
        // Добавляем добавочное-временное имя папки если время не вышло
        $lastChangedTime = $this->config->getUserValue($userId, $this->appName, 'lastChangedTime', '');
        if (time() - $lastChangedTime < 1 * 3600) {
            $tmpFolder = $this->config->getUserValue($userId, $this->appName, 'tmpFolder', '');
            $dataPath = $this->appendSlash($dataPath).$tmpFolder;
        }
        if ($isSeparateFolder)
            $dataPath .= "/".$fileDetails['file_type'];
        return $dataPath;
    }

    private function handleFileErrors($chatId, $msgId, $fileDetails) {
        if ($fileDetails == null) {
            $this->tele->message($chatId, "<b>Файл не загружен.</b> Неизвестный тип.", $msgId);
        } elseif ($fileDetails['file_size'] > 20 * 1024 * 1024) {
            $this->tele->message($chatId, "<b>Файл не загружен.</b> Размер файла превышает 20 Мб.", $msgId);
        }
    }

    function getFileDetails($message) {
        $file_id = null;
        $file_name = null;
        $file_type = null;
        $file_size = null;
        if (isset($message['photo'])) { // Фотография
            $obj = end($message['photo']);
            $file_name = $obj['file_unique_id'].".jpg";
            $file_type = 'photo';
        } elseif (isset($message['video'])) { // Видео "mime_type":"video/mp4"
            $obj = $message['video'];
            $file_name = $obj['file_unique_id'].".".self::getExtension($obj['mime_type']);
            $file_type = 'video';
        } elseif (isset($message['audio'])) { // Аудио "mime_type":"audio/mpeg"
            $obj = $message['audio'];
            if (isset($obj['file_name'])) {
                $file_name = $obj['file_name'];
            } else {
                $file_name = $obj['file_unique_id'].".".self::getExtension($obj['mime_type']);
            }
            $file_type = 'audio';
        } elseif (isset($message['voice'])) { // Воис "mime_type":"audio/ogg"
            $obj = $message['voice'];
            $file_name = $obj['file_unique_id'].".".self::getExtension($obj['mime_type']);
            $file_type = 'voice';
        } elseif (isset($message['video_note'])) { // Видео заметка
            $obj = $message['video_note'];
            $file_name = $obj['file_unique_id'].".mp4";
            $file_type = 'video_note';
        } elseif (isset($message['document'])) { // Документ
            $obj = $message['document'];
            $file_name = $obj['file_name'] ?? $obj['file_unique_id'];
            $file_type = 'file';
        } else
            return null;

        $file_id = $obj['file_id'];
        $file_size = $obj['file_size'];
        return ['file_id' => $file_id, 'file_name' => $file_name, 'file_type' => $file_type, 'file_size' => $file_size];
    }

    private static function getExtension(string $mime_type) {
        $mime_types = array(
            // images
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            // audio
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'mp4',
            'audio/ogg' => 'ogg',
            // video
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/x-msvideo' => 'avi',
            'video/ogg' => 'ogg',
            'video/webm' => 'webm',
            // text
            'text/plain' => 'txt',
            // archives
            'application/x-rar-compressed' => 'rar',
            'application/x-tar' => 'tar',
            'application/zip' => 'zip',
            // microsoft
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            // open office
            'application/vnd.oasis.opendocument.text' => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
            // adobe
            'application/pdf' => 'pdf',
            // json
            'application/json' => 'json'
        );

        if (isset($mime_types[$mime_type])) {
            $result = $mime_types[$mime_type];
        } else {
            $array = explode('/', $mime_type);
            $result = end($array);
        }
        return $result;
    }

    private function getUserByChatId($chatId) {
        $users = $this->userManager->search('', null); // Получение всех пользователей
        foreach ($users as $user) {
            $userChatId = $this->config->getUserValue($user->getUID(), $this->appName, 'chatId', '');
            if ($userChatId == $chatId) {
                return $user; // Возврат найденного пользователя
            }
        }
        return null;
    }

    function createFoldersRecursively($userId, $path) {
        // Берем rootFolder для текущего пользователя
        try {
            $currentFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\OC\User\NoUserException $e) {
            return http_response_code(200);
        }
        $folders = explode('/', trim($path, '/')); // Удаляем лишние слеши и разбиваем путь на части

        foreach ($folders as $folderName) {
            if ($folderName) { // Проверяем, чтобы имя папки было не пустым
                try {
                    $currentFolder = $currentFolder->get($folderName);
                } catch (\OCP\Files\NotFoundException $e) {
                    $currentFolder = $currentFolder->newFolder($folderName);
                }
            }
        }

        return $currentFolder;
    }
}

class TelegramBot
{
    private $token;
    private $url;

    public function __construct($token)
    {
        $this->token = $token;
        $this->url = 'https://api.telegram.org/bot' . $this->token . '/';
    }

    function getTelegramFile($file_id, $chatId)
    {
        $telegramApiUrl = "https://api.telegram.org/bot{$this->token}/getFile?file_id={$file_id}";
        $ch = curl_init($telegramApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($resp, true);
        if ($data['ok'] === true) {
            $file_path = $data['result']['file_path'];
            $fileUrl = "https://api.telegram.org/file/bot{$this->token}/$file_path";
            $ch = curl_init($fileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $fileContent = curl_exec($ch);
            curl_close($ch);

            if ($fileContent !== false) {
                return $fileContent;
            }
            return false;
        } else {
            $this->message($chatId,
                "Ошибка: {$data['description']}");
        }

        return false;
    }

    function message($chat_id, $text, $msgId = null, $buttons = [], $keyboard = [])
    {
        $url = $this->url . 'sendMessage';

        $pars['chat_id'] = $chat_id;
        $pars['text'] = substr($text, 0, 4096);
        $pars['parse_mode'] = 'HTML';
        $pars['disable_web_page_preview'] = true;

        if (!empty($buttons))
            $pars['reply_markup']['inline_keyboard'] = [$buttons];

        if (!empty($keyboard)) {
            $pars['reply_markup']['keyboard'] = $keyboard;
            $pars['reply_markup']['resize_keyboard'] = true;
        }

        if (!is_null($msgId)) {
            $pars['reply_to_message_id'] = $msgId; // Ответ на сообщение
        }

        $content = json_encode($pars);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        $resp = curl_exec($ch);
        curl_close($ch);

        $resp = json_decode($resp, true);
        if (!$resp['ok']) {
            if ($resp['error_code'] == 429) {
                $timeout = $resp['parameters']['retry_after'] + 1;
                sleep($timeout);
                return $resp;
            } else
                $this->message($chat_id, "<b>Ошибка отправки сообщения:</b> " . $resp['description']);
        }
        return $resp;
    }
}
