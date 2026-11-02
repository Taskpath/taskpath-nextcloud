<?php

declare(strict_types=1);

namespace OCA\Taskpath\Controller;

use OCA\Taskpath\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/** The Taskpath page in the app menu: first run, then the inbox. */
class PageController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'taskpath-main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
