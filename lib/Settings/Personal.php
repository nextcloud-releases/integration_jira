<?php
namespace OCA\Jira\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IURLGenerator;
use OCP\IInitialStateService;

use OCA\Jira\AppInfo\Application;

class Personal implements ISettings {

    private $request;
    private $config;
    private $dataDirPath;
    private $urlGenerator;
    private $l;

    public function __construct(string $appName,
                                IL10N $l,
                                IRequest $request,
                                IConfig $config,
                                IURLGenerator $urlGenerator,
                                IInitialStateService $initialStateService,
                                $userId) {
        $this->appName = $appName;
        $this->urlGenerator = $urlGenerator;
        $this->request = $request;
        $this->l = $l;
        $this->config = $config;
        $this->initialStateService = $initialStateService;
        $this->userId = $userId;
    }

    /**
     * @return TemplateResponse
     */
    public function getForm() {
        $token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
        $url = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', '');
        $searchEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'search_enabled', '0');
        $notificationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'notification    _enabled', '0');

        // for OAuth
        $clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
        // don't expose the client secret to users
        $clientSecret = ($this->config->getAppValue(Application::APP_ID, 'client_secret', '') !== '');

        $userConfig = [
            'token' => $token,
            'url' => $url,
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'search_enabled' => ($searchEnabled === '1'),
            'notification_enabled' => ($notificationEnabled === '1'),
        ];
        $this->initialStateService->provideInitialState($this->appName, 'user-config', $userConfig);
        return new TemplateResponse(Application::APP_ID, 'personalSettings');
    }

    public function getSection() {
        return 'connected-accounts';
    }

    public function getPriority() {
        return 10;
    }
}
