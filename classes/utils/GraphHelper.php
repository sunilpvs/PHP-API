
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

class GraphHelper
{
    private $logger;

    public function __construct()
    {
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getManagerForUser($authToken, $userEmail, $module, $username)
    {
        try {
            if (!$authToken) {
                $this->logger->log('No access token available for Microsoft Graph API', 'classes', $module, $username);
                return null;
            }

            $client = new \Microsoft\Graph\Graph();
            $client->setAccessToken($authToken);

            $manager = $client->createRequest('GET', '/users/' . $userEmail . '/manager')
                ->setReturnType(\Microsoft\Graph\Model\User::class)
                ->execute();

            return $manager;
        } catch (Exception $e) {
            $this->logger->log('Error fetching manager for user ' . $userEmail . ': ' . $e->getMessage(), 'classes', $module, $username);
            return null;
        }
    }

}
