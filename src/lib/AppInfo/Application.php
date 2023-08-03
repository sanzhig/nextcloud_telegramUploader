<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: sanzhig <postmansan@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TelegramUploader\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
	public const APP_ID = 'telegramuploader';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}
}
