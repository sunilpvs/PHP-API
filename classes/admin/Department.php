<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Department {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllDepartments($module, $username) {
        $query = 'SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_department d
                  LEFT JOIN tbl_status s ON d.status = s.code';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedDepartments($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_department d
                  LEFT JOIN tbl_status s ON d.status = s.id
                  ORDER BY d.name ASC
                  LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getDepartmentsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_department';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getDepartmentById($id, $module, $username) {
        $query = 'SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_department d
                  LEFT JOIN tbl_status s ON d.status = s.id
                  WHERE d.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getDepartmentByCode($code, $module, $username) {
        $query = 'SELECT 
                    d.id, 
                    d.name, 
                    d.code, 
                    d.status AS status_id, 
                    s.status AS status 
                  FROM tbl_department d
                  LEFT JOIN tbl_status s ON d.status = s.code
                  WHERE d.code = ?';
        $this->logger->logQuery($query, [$code], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$code]);
    }

    public function insertDepartment($name, $code, $status, $module, $username) {
        $query = 'INSERT INTO tbl_department (name, code, status) VALUES (?, ?, ?)';
        $this->logger->logQuery($query, [$name, $code, $status], 'classes', $module, $username);
        $logMessage = 'Department Inserted ';
        return $this->conn->insert($query, [$name, $code, $status], $logMessage);
    }

    public function updateDepartment($id, $name, $code, $status, $module, $username) {
        $query = 'UPDATE tbl_department SET name = ?, code = ?, status = ? WHERE id = ?';
        $this->logger->logQuery($query, [$name, $code, $status, $id], 'classes', $module, $username);
        return $this->conn->update($query, [$name, $code, $status, $id]);
    }

    public function deleteDepartment($id, $module, $username) {
        $query = 'DELETE FROM tbl_department WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->update($query, [$id]);
    }
}

?>
