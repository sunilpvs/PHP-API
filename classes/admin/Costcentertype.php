<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class CostCenterType {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllCostCenterTypes($module, $username) {
        $query = 'SELECT id, cc_type FROM tbl_costcentertype';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedCostCenterTypes($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT id, cc_type 
                  FROM tbl_costcentertype
                  ORDER BY cc_type ASC
                  LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getCostCenterTypesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_costcentertype';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getCostCenterTypeById($id, $module, $username) {
        $query = 'SELECT id, cc_type FROM tbl_costcentertype WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getCostCenterTypeByName($cc_type, $module, $username) {
        $query = 'SELECT id, cc_type FROM tbl_costcentertype WHERE cc_type = ?';
        $this->logger->logQuery($query, [$cc_type], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$cc_type]);
    }

    public function insertCostCenterType($cc_type, $module, $username) {
        $query = 'INSERT INTO tbl_costcentertype (cc_type) VALUES (?)';
        $this->logger->logQuery($query, [$cc_type], 'classes', $module, $username);
        $logMessage = 'Cost Center Type Inserted ';
        return $this->conn->insert($query, [$cc_type], $logMessage);
    }

    public function updateCostCenterType($id, $cc_type, $module, $username) {
        $query = 'UPDATE tbl_costcentertype SET cc_type = ? WHERE id = ?';
        $this->logger->logQuery($query, [$cc_type, $id], 'classes', $module, $username);
        $logMessage = 'Cost Center Type Updated ';
        return $this->conn->update($query, [$cc_type, $id], $logMessage);
    }

    public function deleteCostCenterType($id, $module, $username) {
        $query = 'DELETE FROM tbl_costcentertype WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'Cost Center Type Deleted ';
        return $this->conn->update($query, [$id], $logMessage);
    }
}
?>
