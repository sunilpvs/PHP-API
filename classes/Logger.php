<?php
// namespace PVSDBLib;
// By default write to db 
// When debug mode is enabled, write to db and file
require_once __DIR__ . '/authentication/LoginUser.php';
class Logger
{
    private $debugMode;
    private $logDir;
    private $username;

    public function __construct($debugMode, $logDir)
    {
        $this->debugMode = $this->resolveDebugMode($debugMode);
        $this->logDir = $this->resolveLogDirectory($logDir);

        $this->initializeLogDirectory();
    }

    private function resolveDebugMode($debugMode)
    {
        if (is_bool($debugMode)) {
            if ($debugMode === true) {
                return true;
            }
        } elseif ($debugMode !== null) {
            return in_array(strtolower(trim((string)$debugMode)), ['1', 'true', 'yes', 'on'], true);
        }

        $configPath = $_SERVER['DOCUMENT_ROOT'] . '/app.ini';
        if (is_file($configPath)) {
            $config = parse_ini_file($configPath, true);
            if (
                is_array($config) &&
                isset($config['generic']) &&
                isset($config['generic']['DEBUG_MODE'])
            ) {
                return in_array(strtolower(trim((string)$config['generic']['DEBUG_MODE'])), ['1', 'true', 'yes', 'on'], true);
            }
        }

        return false;
    }

    private function resolveLogDirectory($logDir)
    {
        $primaryDir = rtrim((string)$logDir, '/\\');
        if ($primaryDir !== '') {
            return $primaryDir;
        }

        return realpath(__DIR__ . '/..') . '/logs';
    }

    private function initializeLogDirectory()
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }

        if (!is_dir($this->logDir) || !is_writable($this->logDir)) {
            $fallbackDir = realpath(__DIR__ . '/..') . '/logs';
            if (!is_dir($fallbackDir)) {
                @mkdir($fallbackDir, 0775, true);
            }
            if (is_dir($fallbackDir) && is_writable($fallbackDir)) {
                $this->logDir = $fallbackDir;
            }
        }
    }

    private function getLogFilePath($type = 'api')
    {
        $date = date('dmY');
        return $this->logDir . "/{$type}_{$date}.log";
    }

    public function log($message, $level = 'INFO', $type = 'api')
    {
        if ($this->debugMode) {
            $formattedMessage = "[" . date('Y-m-d H:i:s') . "] [$level] $message" . PHP_EOL;
            file_put_contents($this->getLogFilePath($type), $formattedMessage, FILE_APPEND);
        }
    }

    public function logRequestAndResponse($request, $response)
    {
        if ($this->debugMode) {
            $requestDetails = [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $_SERVER['REQUEST_URI'],
                'query' => $_GET,
                'body' => $request
            ];
            $this->log("Request: " . json_encode($requestDetails), 'REQUEST');
            $this->log("Response: " . json_encode($response), 'RESPONSE');
        }
    }

    public function logQuery($query, $params = [], $type = 'classes', $module = 'unknown')
    {
        $auth = new UserLogin();
        $this->username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
        if ($this->debugMode) {
            $formattedMessage = "Module: $module | Username: $this->username | Query: $query | Params: " . json_encode($params);
            $this->log($formattedMessage, 'QUERY', $type);
        }
    }
}
