<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

// -- Table for leave policies
// CREATE TABLE tbl_leave_policy (
//     id INT PRIMARY KEY AUTO_INCREMENT,
//     leave_type INT NOT NULL,
//     annual_quota INT NOT NULL,
//     year INT NOT NULL,
//     carry_forward BOOLEAN NOT NULL,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     created_by INT NOT NULL,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     updated_by INT NOT NULL
// );

class LeavePolicy {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllLeavePolicies($module,  $username) {
        $query = 'SELECT lp.*, lt.leave_type_name FROM tbl_leave_policy lp JOIN tbl_leave_types lt ON lp.leave_type = lt.id';
        $this->logger->logQuery($query, [], 'classes', $module,  $username);
        return $this->conn->runQuery($query);
    }

    public function getLeavePolicyById($id, $module, $username) {
        $query = 'SELECT lp.*, lt.leave_type_name FROM tbl_leave_policy lp JOIN tbl_leave_types lt ON lp.leave_type = lt.id WHERE lp.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $result = $this->conn->runQuery($query, [$id]);
        return isset($result[0]) ? $result[0] : null;
    }

    // Additional methods for creating, updating, and deleting leave policies can be added here

    public function getLeavePoliciesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_leave_policy';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function createLeavePolicy($leaveType, $annualQuota, $year, $carryForward, $createdBy, $module, $username) {
        $query = 'INSERT INTO tbl_leave_policy (leave_type, annual_quota, year, carry_forward, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$leaveType, $annualQuota, $year, $carryForward, $createdBy, $createdBy], 'classes', $module, $username);
        return $this->conn->insert($query, [$leaveType, $annualQuota, $year, $carryForward, $createdBy, $createdBy]);
    }

    public function updateLeavePolicy($id, $leaveType, $annualQuota, $year, $carryForward, $updatedBy, $module, $username) {
        $query = 'UPDATE tbl_leave_policy SET leave_type = ?, annual_quota = ?, year = ?, carry_forward = ?, updated_by = ? WHERE id = ?';
        $this->logger->logQuery($query, [$leaveType, $annualQuota, $year, $carryForward, $updatedBy, $id], 'classes', $module, $username);
        return $this->conn->update($query, [$leaveType, $annualQuota, $year, $carryForward, $updatedBy, $id]);
    }

    public function deleteLeavePolicy($id, $module, $username) {
        $query = 'DELETE FROM tbl_leave_policy WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->delete($query, [$id]);
    }

    public function getLeavePoliciesByYear($year, $module, $username) {
        $query = 'SELECT lp.*, lt.leave_type_name FROM tbl_leave_policy lp JOIN tbl_leave_types lt ON lp.leave_type = lt.id WHERE lp.year = ?';
        $this->logger->logQuery($query, [$year], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$year]);
    }

}