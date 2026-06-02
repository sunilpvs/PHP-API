<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

// CREATE TABLE tbl_leave_types (
//     id INT PRIMARY KEY AUTO_INCREMENT,
//     leave_type_name VARCHAR(255) NOT NULL,
//     description TEXT,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     created_by INT NOT NULL,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     updated_by INT NOT NULL
// );

class LeaveTypes {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllLeaveTypes($module,  $username) {
        $query = 'SELECT * FROM tbl_leave_types';
        $this->logger->logQuery($query, [], 'classes', $module,  $username);
        return $this->conn->runQuery($query);
    }

    public function getLeaveTypeById($id, $module, $username) {
        $query = 'SELECT * FROM tbl_leave_types WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $result = $this->conn->runQuery($query, [$id]);
        return isset($result[0]) ? $result[0] : null;
    }

    public function createLeaveType($leaveTypeName, $description, $createdBy, $module, $username) {
        $query = 'INSERT INTO tbl_leave_types (leave_type_name, description, created_by, updated_by) VALUES (?, ?, ?, ?)';
        $this->logger->logQuery($query, [$leaveTypeName, $description, $createdBy, $createdBy], 'classes', $module, $username);
        return $this->conn->insert($query, [$leaveTypeName, $description, $createdBy, $createdBy]);
    }

    public function updateLeaveType($id, $leaveTypeName, $description, $updatedBy, $module, $username) {
        $query = 'UPDATE tbl_leave_types SET leave_type_name = ?, description = ?, updated_by = ? WHERE id = ?';
        $this->logger->logQuery($query, [$leaveTypeName, $description, $updatedBy, $id], 'classes', $module, $username);
        return $this->conn->update($query, [$leaveTypeName, $description, $updatedBy, $id]);
    }

    public function deleteLeaveType($id, $module, $username) {
        $query = 'DELETE FROM tbl_leave_types WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->delete($query, [$id]);
    }

    public function getLeaveTypesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_leave_types';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }



}
