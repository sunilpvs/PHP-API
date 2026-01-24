<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Designation {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllDesignations($module, $username) {
        $query = 'SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_designation d
                  LEFT JOIN tbl_status s ON d.status = s.id';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedDesignations($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_designation d
                  LEFT JOIN tbl_status s ON d.status = s.id
                  ORDER BY d.name ASC
                  LIMIT $limit OFFSET $offset";

        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getDesignationsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_designation';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getDesignationById($id, $module, $username) {
        $query = 'SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_designation d
                  LEFT JOIN tbl_status s ON d.status = s.id
                  WHERE d.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getDesignationByCode($code, $module, $username) {
        $query = 'SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_designation d
                  LEFT JOIN tbl_status s ON d.status = s.id
                  WHERE d.code = ?';
        $this->logger->logQuery($query, [$code], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$code]);
    }

    public function getDesignationCombo(array $fields, $module, $username) {
        $allowedFields = ['id', 'name', 'code', 'status'];
        $query = $this->conn->buildSelectQuery('tbl_designation', $fields, $allowedFields, 'name ASC');

        if (!$query) {
            $this->logger->log("Invalid field selection for designation combo", 'classes', $module, $username);
            return [];
        }

        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function insertDesignation($name, $code, $status, $module, $username) {
        $query = 'INSERT INTO tbl_designation (name, code, status) VALUES (?, ?, ?)';
        $this->logger->logQuery($query, [$name, $code, $status], 'classes', $module, $username);
        $logMessage = 'Designation Inserted ';
        return $this->conn->insert($query, [$name, $code, $status], $logMessage);
    }

    public function updateDesignation($name, $code, $status, $id, $module, $username) {
        $query = 'UPDATE tbl_designation SET name = ?, code = ?, status = ? WHERE id = ?';
        $this->logger->logQuery($query, [$name, $code, $status, $id], 'classes', $module, $username);
        $logMessage = 'Designation Updated ';
        return $this->conn->update($query, [$name, $code, $status, $id], $logMessage);
    }

    public function deleteDesignation($id, $module, $username) {
        $query = 'DELETE FROM tbl_designation WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'Designation Deleted ';
        return $this->conn->update($query, [$id],);
    }

    public function checkDuplicateDesignation($name, $code) {
        $query = 'SELECT 1 FROM tbl_designation WHERE lower(trim(name)) = lower(trim(?)) OR lower(trim(code)) = lower(trim(?))';
        $this->logger->logQuery($query, [$name, $code], 'classes');
        $result = $this->conn->runQuery($query, [$name, $code]);
        return !empty($result);
    }
}
