<?php
/**
 * Nextcloud - jira
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Jira\Service;

use OCP\IL10N;
use OCP\ILogger;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager as INotificationManager;
use GuzzleHttp\Exception\ClientException;

use OCA\Jira\AppInfo\Application;

class JiraAPIService {

    private $l10n;
    private $logger;

    /**
     * Service to make requests to Jira v3 (JSON) API
     */
    public function __construct (IUserManager $userManager,
                                string $appName,
                                ILogger $logger,
                                IL10N $l10n,
                                IConfig $config,
                                INotificationManager $notificationManager,
                                IClientService $clientService) {
        $this->appName = $appName;
        $this->l10n = $l10n;
        $this->logger = $logger;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->clientService = $clientService;
        $this->notificationManager = $notificationManager;
        $this->client = $clientService->newClient();
    }

    /**
     * triggered by a cron job
     * notifies user of their number of new tickets
     */
    public function checkOpenTickets(): void {
        $this->userManager->callForAllUsers(function (IUser $user) {
            $this->checkOpenTicketsForUser($user->getUID());
        });
    }

    private function checkOpenTicketsForUser(string $userId): void {
        $accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
        if ($accessToken) {
            $refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
            $clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
            $clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
            if ($clientID && $clientSecret && $jiraUrl) {
                $lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check', '');
                $lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;
                // get the jira user ID
                $me = $this->request(
                    $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'users/me'
                );
                if (isset($me['id'])) {
                    $my_user_id = $me['id'];

                    $notifications = $this->getNotifications(
                        $jiraUrl, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, $lastNotificationCheck
                    );
                    if (!isset($notifications['error']) && count($notifications) > 0) {
                        $lastNotificationCheck = $notifications[0]['updated_at'];
                        $this->config->setUserValue($userId, Application::APP_ID, 'last_open_check', $lastNotificationCheck);
                        $nbOpen = 0;
                        foreach ($notifications as $n) {
                            $user_id = $n['user_id'];
                            $state_id = $n['state_id'];
                            $owner_id = $n['owner_id'];
                            // if ($state_id === 1) {
                            if ($owner_id === $my_user_id && $state_id === 1) {
                                $nbOpen++;
                            }
                        }
                        error_log('NB OPEN for '.$me['lastname'].': '.$nbOpen);
                        if ($nbOpen > 0) {
                            $this->sendNCNotification($userId, 'new_open_tickets', [
                                'nbOpen' => $nbOpen,
                                'link' => $jiraUrl
                            ]);
                        }
                    }
                }
            }
        }
    }

    private function sendNCNotification(string $userId, string $subject, array $params): void {
        $manager = $this->notificationManager;
        $notification = $manager->createNotification();

        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('dum', 'dum')
            ->setSubject($subject, $params);

        $manager->notify($notification);
    }

    public function getNotifications(string $accessToken,
                                    string $refreshToken, string $clientID, string $clientSecret, string $userId,
                                    ?string $since = null, ?int $limit = null): array {
        $params = [
            'state' => 'pending',
        ];
        $result = $this->request(
            $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'online_notifications', $params
        );
        if (isset($result['error'])) {
            return $result;
        }
        // filter seen ones
        $result = array_filter($result, function($elem) {
            return !$elem['seen'];
        });
        // filter results by date
        if (!is_null($since)) {
            $sinceDate = new \DateTime($since);
            $sinceTimestamp = $sinceDate->getTimestamp();
            $result = array_filter($result, function($elem) use ($sinceTimestamp) {
                $date = new \Datetime($elem['updated_at']);
                $ts = $date->getTimestamp();
                return $ts > $sinceTimestamp;
            });
        }
        if ($limit) {
            $result = array_slice($result, 0, $limit);
        }
        $result = array_values($result);
        // get details
        foreach ($result as $k => $v) {
            $details = $this->request(
                $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'tickets/' . $v['o_id']
            );
            if (!isset($details['error'])) {
                $result[$k]['title'] = $details['title'];
                $result[$k]['note'] = $details['note'];
                $result[$k]['state_id'] = $details['state_id'];
                $result[$k]['owner_id'] = $details['owner_id'];
                $result[$k]['type'] = $details['type'];
            }
        }
        // get user details
        $userIds = [];
        foreach ($result as $k => $v) {
            if (!in_array($v['updated_by_id'], $userIds)) {
                array_push($userIds, $v['updated_by_id']);
            }
        }
        $userDetails = [];
        foreach ($userIds as $uid) {
            $user = $this->request(
                $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'users/' . $uid
            );
            $userDetails[$uid] = [
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'organization_id' => $user['organization_id'],
                'image' => $user['image'],
            ];
        }
        foreach ($result as $k => $v) {
            $user = $userDetails[$v['updated_by_id']];
            $result[$k]['firstname'] = $user['firstname'];
            $result[$k]['lastname'] = $user['lastname'];
            $result[$k]['organization_id'] = $user['organization_id'];
            $result[$k]['image'] = $user['image'];
        }

        return $result;
    }

    public function search(string $accessToken,
                            string $refreshToken, string $clientID, string $clientSecret, string $userId,
                            string $query): array {
        $params = [
            'query' => $query,
            'limit' => 10,
        ];
        $searchResult = $this->request(
            $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'tickets/search', $params
        );

        $result = [];
        if (isset($searchResult['assets']) && isset($searchResult['assets']['Ticket'])) {
            foreach ($searchResult['assets']['Ticket'] as $id => $t) {
                array_push($result, $t);
            }
        }
        return $result;
    }

    // authenticated request to get an image from jira
    public function getJiraAvatar(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
                                  string $image): string {
        $url = $url . '/api/v1/users/image/' . $image;
        $options = [
            'headers' => [
                'Authorization'  => 'Bearer ' . $accessToken,
                'User-Agent' => 'Nextcloud Jira integration',
            ]
        ];
        return $this->client->get($url, $options)->getBody();
    }

    public function request(string $accessToken, string $refreshToken,
                            string $clientID, string $clientSecret, string $userId,
                            string $endPoint, array $params = [], string $method = 'GET'): array {
        try {
            $url = Application::JIRA_API_URL . '/' . $endPoint;
            $options = [
                'headers' => [
                    'Authorization'  => 'Bearer ' . $accessToken,
                    'User-Agent' => 'Nextcloud Jira integration',
                ]
            ];

            if (count($params) > 0) {
                if ($method === 'GET') {
                    // manage array parameters
                    $paramsContent = '';
                    foreach ($params as $key => $value) {
                        if (is_array($value)) {
                            foreach ($value as $oneArrayValue) {
                                $paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
                            }
                            unset($params[$key]);
                        }
                    }
                    $paramsContent .= http_build_query($params);
                    $url .= '?' . $paramsContent;
                } else {
                    $options['body'] = $params;
                }
            }

            if ($method === 'GET') {
                $response = $this->client->get($url, $options);
            } else if ($method === 'POST') {
                $response = $this->client->post($url, $options);
            } else if ($method === 'PUT') {
                $response = $this->client->put($url, $options);
            } else if ($method === 'DELETE') {
                $response = $this->client->delete($url, $options);
            }
            $body = $response->getBody();
            $respCode = $response->getStatusCode();

            if ($respCode >= 400) {
                return ['error' => $this->l10n->t('Bad credentials')];
            } else {
                return json_decode($body, true);
            }
        } catch (ClientException $e) {
            $this->logger->warning('Jira API error : '.$e->getMessage(), array('app' => $this->appName));
            $response = $e->getResponse();
            $body = (string) $response->getBody();
            // refresh token if it's invalid and we are using oauth
            // response can be : 'OAuth2 token is expired!', 'Invalid token!' or 'Not authorized'
            if (strpos($body, 'expired') !== false) {
                $this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
                // try to refresh the token
                $result = $this->requestOAuthAccessToken(Application::JIRA_API_URL, [
                    'client_id' => $clientID,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ], 'POST');
                if (isset($result['access_token'])) {
                    $accessToken = $result['access_token'];
                    $this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
                    // retry the request with new access token
                    return $this->request(
                        $accessToken, $refreshToken, $clientID, $clientSecret, $userId, $endPoint, $params, $method
                    );
                }
            }
            return ['error' => $e->getMessage()];
        }
    }

    public function requestOAuthAccessToken(array $params = [], string $method = 'GET'): array {
        try {
            $url = Application::JIRA_AUTH_URL . '/oauth/token';
            $options = [
                'headers' => [
                    'User-Agent'  => 'Nextcloud Jira integration',
                ]
            ];

            if (count($params) > 0) {
                if ($method === 'GET') {
                    $paramsContent = http_build_query($params);
                    $url .= '?' . $paramsContent;
                } else {
                    $options['body'] = $params;
                }
            }

            if ($method === 'GET') {
                $response = $this->client->get($url, $options);
            } else if ($method === 'POST') {
                $response = $this->client->post($url, $options);
            } else if ($method === 'PUT') {
                $response = $this->client->put($url, $options);
            } else if ($method === 'DELETE') {
                $response = $this->client->delete($url, $options);
            }
            $body = $response->getBody();
            $respCode = $response->getStatusCode();

            if ($respCode >= 400) {
                return ['error' => $this->l10n->t('OAuth access token refused')];
            } else {
                return json_decode($body, true);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Jira OAuth error : '.$e->getMessage(), array('app' => $this->appName));
            return ['error' => $e->getMessage()];
        }
    }

}
