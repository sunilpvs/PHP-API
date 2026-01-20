<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class ContactType {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllContactTypes($module, $username) {
        $query = 'SELECT ct.id, ct.name, ct.status AS status_id, s.status AS status_name 
                  FROM tbl_contacttype ct
                  LEFT JOIN tbl_status s ON ct.status = s.id';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedContactTypes($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT ct.id, ct.name, ct.status AS status_id, s.status AS status_name
                  FROM tbl_contacttype ct
                  LEFT JOIN tbl_status s ON ct.status = s.id
                  ORDER BY ct.name ASC
                  LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getContactTypesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_contacttype';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getContactTypeById($id, $module, $username) {
        $query = 'SELECT ct.id, ct.name, ct.status AS status_id, s.status AS status_name
                  FROM tbl_contacttype ct
                  LEFT JOIN tbl_status s ON ct.status = s.id
                  WHERE ct.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getContactTypeByName($name, $module, $username) {
        $query = 'SELECT ct.id, ct.name, ct.status AS status_id, s.status AS status_name
                  FROM tbl_contacttype ct
                  LEFT JOIN tbl_status s ON ct.status = s.id
                  WHERE ct.name = ?';
        $this->logger->logQuery($query, [$name], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$name]);
    }

    public function insertContactType($name, $status, $module, $username) {
        $query = 'INSERT INTO tbl_contacttype (name, status) VALUES (?, ?)';
        $this->logger->logQuery($query, [$name, $status], 'classes', $module, $username);
        $logMessage = 'Contact Type Inserted ';
        return $this->conn->insert($query, [$name, $status], $logMessage);
    }

    public function updateContactType($id, $name, $status, $module, $username) {
        $query = 'UPDATE tbl_contacttype SET name = ?, status = ? WHERE id = ?';
        $this->logger->logQuery($query, [$name, $status, $id], 'classes', $module, $username);
        $logMessage = 'Contact Type Updated ';
        return $this->conn->update($query, [$name, $status, $id], $logMessage);
    }

    public function deleteContactType($id, $module, $username) {
        $query = 'DELETE FROM tbl_contacttype WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'Contact Type Deleted ';
        return $this->conn->update($query, [$id], $logMessage);
    }
}
?>
