<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Status {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllStatuses($module, $username) {
        $query = 'SELECT * FROM tbl_status';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedStatuses($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT id, code, status, module FROM tbl_status ORDER BY status ASC LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getStatusesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_status';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getStatusByCode($code, $module, $username) {
        $query = 'SELECT id, code, status, module FROM tbl_status WHERE code = ?';
        $this->logger->logQuery($query, [$code], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$code]);
    }

    public function getStatusByCodeAndModule($code, $moduleValue, $module, $username) {
        $query = 'SELECT id, code, status, module FROM tbl_status WHERE code = ? AND module = ?';
        $this->logger->logQuery($query, [$code, $moduleValue], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$code, $moduleValue]);
    }

    public function getStatusByModule($moduleValue, $module, $username) {
        $query = 'SELECT id, status FROM tbl_status WHERE module = ?';
        $this->logger->logQuery($query, [$moduleValue], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$moduleValue]);
    }


    public function insertStatus($code, $status, $moduleValue, $module, $username) {
        $query = 'INSERT INTO tbl_status (code, status, module) VALUES (?, ?, ?)';
        $this->logger->logQuery($query, [$code, $status, $moduleValue], 'classes', $module, $username);
        $logMessage = 'Status Inserted ';
        return $this->conn->insert($query, [$code, $status, $moduleValue], $logMessage);
    }

    public function updateStatus($code, $status, $moduleValue, $module, $username) {
        $query = 'UPDATE tbl_status SET status = ?, module = ? WHERE code = ?';
        $this->logger->logQuery($query, [$status, $moduleValue, $code], 'classes', $module, $username);
        $logMessage = 'Status Updated ';
        return $this->conn->update($query, [$status, $moduleValue, $code], $logMessage);
    }

    public function deleteStatus($code, $module, $username) {
        $query = 'DELETE FROM tbl_status WHERE id = ?';
        $this->logger->logQuery($query, [$code], 'classes', $module, $username);
        $logMessage = 'Status Deleted ';
        return $this->conn->update($query, [$code], $logMessage);
    }

    public function checkDuplicateStatus($code, $moduleValue) {
        $query = 'SELECT 1 FROM tbl_status WHERE lower(trim(code)) = lower(trim(?)) AND lower(trim(module)) = lower(trim(?))';
        $this->logger->logQuery($query, [$code, $moduleValue], 'classes');
        $result = $this->conn->runQuery($query, [$code, $moduleValue]);
        return !empty($result);
    }

    public function getStatusCombo(array $fields, $module, $username) {
        $allowedFields = ['id', 'code', 'status', 'module'];
        $query = $this->conn->buildSelectQuery('tbl_status', $fields, $allowedFields, 'status ASC');

        if (!$query) {
            $this->logger->log("Invalid field selection for status combo", 'classes', $module, $username);
            return [];
        }

        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }
}
