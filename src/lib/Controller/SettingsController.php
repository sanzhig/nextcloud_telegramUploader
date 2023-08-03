<?php
// SettingsController.php
namespace OCA\TelegramUploader\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\DataResponse;
use OCP\IUserSession;
use OCP\AppFramework\Http\RedirectResponse;

class SettingsController extends Controller {
    private $config;
    private $urlGenerator;
    private $userId;

    public function __construct(string $appName, IRequest $request, IUserSession $userSession, IURLGenerator $urlGenerator, IConfig $config){
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->userId = $userSession->getUser()->getUID();
    }
    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     */
    public function index($webhook_result = null) {
        $parameters = [
            'bot_token' => $this->config->getUserValue($this->userId, $this->appName, 'bot_token', ''),
            'chatId' => $this->config->getUserValue($this->userId, $this->appName, 'chatId', ''),
            'tgRootPath' => $this->config->getUserValue($this->userId, $this->appName, 'tgRootPath', ''),
            'isSeparateFolder' => $this->config->getUserValue($this->userId, $this->appName, 'isSeparateFolder', '0') === '1',
            'urlGenerator' => $this->urlGenerator,
            'webhook_result' => $webhook_result
        ];
        return new TemplateResponse($this->appName, 'settings', $parameters);
    }
    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     */
    public function save($bot_token, $chatId, $tgRootPath, $isSeparateFolder) {
        $this->config->setUserValue($this->userId, $this->appName, 'bot_token', $bot_token);
        $this->config->setUserValue($this->userId, $this->appName, 'chatId', $chatId);
        $this->config->setUserValue($this->userId, $this->appName, 'tgRootPath', $tgRootPath);
        $this->config->setUserValue($this->userId, $this->appName, 'isSeparateFolder', $isSeparateFolder ? '1' : '0');

        $webhook_url = $this->urlGenerator->linkToRouteAbsolute('telegramuploader.webhook.index')."index.php/apps/telegramuploader/webhook";
        $webhook_result = $this->setWebHook($bot_token, $webhook_url);
        $url = $this->urlGenerator->linkToRoute('telegramuploader.settings.index', ['webhook_result' => $webhook_result]);
        return new RedirectResponse($url);
    }

    private function setWebHook($bot_token, $url) {
        $url = 'https://api.telegram.org/bot' . $bot_token . '/setWebhook?url=' . $url;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}
