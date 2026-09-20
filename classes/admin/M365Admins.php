

<?php

/* CREATE TABLE tbl_m365_admins ( - tbl_notification
	id INT PRIMARY KEY,
	employee_id INT NOT NULL,
    corrections - module_id (from user modules),
    action varchar - emp add, emp 
    email-message
    email_status
    whatsapp message
    
    status INT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_by INT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_employee FOREIGN KEY (employee_id) REFERENCES tbl_employee(id),
    CONSTRAINT fk_status FOREIGN KEY (status) REFERENCES tbl_status(id)
); */

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class M365Admins {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    private function getM365Admins() {
        return "SELECT
                    m365.id,
                    m365.employee_id,
                    emp.emp_code,
                    CONCAT(cont.f_name, ' ', cont.l_name) AS employee_name,
                    m365.status AS status_id,
                    status.status,
                    m365.created_by,
                    m365.created_at,
                    m365.updated_by,
                    m365.updated_at
                FROM tbl_m365_admins m365
                JOIN tbl_employee emp ON m365.employee_id = emp.id
                LEFT JOIN tbl_contact cont ON emp.contact_id = cont.id
                LEFT JOIN tbl_status status ON m365.status = status.id";
    }

    public function getAllM365Admins($module, $username) {
        $query = $this->getM365Admins() . ' ORDER BY m365.id ASC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedM365Admins($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $offset);

        $query = $this->getM365Admins() . " ORDER BY m365.id ASC LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getActiveM365Admins($module, $username) {
        $query = $this->getM365Admins() . ' WHERE m365.status = 1 ORDER BY m365.id ASC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getInActiveM365Admins($module, $username) {
        $query = $this->getM365Admins() . ' WHERE m365.status = 2 ORDER BY m365.id ASC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getM365AdminById($id, $module, $username) {
        $query = $this->getM365Admins() . ' WHERE m365.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getM365AdminsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_m365_admins';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int) $result[0]['total'] : 0;
    }

    public function addM365Admin($employeeId, $status, $createdBy, $module, $username) {
        $status = (int) $status;
        if (!in_array($status, [1, 2], true)) {
            throw new InvalidArgumentException('M365 admin status must be either 1 (Active) or 2 (In-Active).');
        }

        $query = 'INSERT INTO tbl_m365_admins (employee_id, status, created_by, updated_by) VALUES (?, ?, ?, ?)';
        $params = [$employeeId, $status, $createdBy, $createdBy];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->insert($query, $params, 'M365 Admin Inserted ');
    }

    public function updateM365Admin($employeeId, $status, $updatedBy, $id, $module, $username) {
        $query = 'UPDATE tbl_m365_admins SET employee_id = ?, status = ?, updated_by = ? WHERE id = ?';
        $params = [$employeeId, $status, $updatedBy, $id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->update($query, $params, 'M365 Admin Updated ');
    }

    public function updateM365AdminStatus($employeeId, $statusId, $module, $username) {
        $query = 'UPDATE tbl_m365_admins SET status = ?, updated_by = ? WHERE employee_id = ?';
        $params = [$statusId, $username, $employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->update($query, $params, 'M365 admin status updated');
    }

    public function isM365Admin($employeeId, $module, $username) {
        $query = 'SELECT 1 FROM tbl_m365_admins WHERE employee_id = ? AND status = 1';
        $params = [$employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return (bool) $this->conn->runSingle($query, $params);
    }

    public function checkDuplicateM365Admin($employeeId) {
        $query = 'SELECT 1 FROM tbl_m365_admins WHERE employee_id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes');
        return !empty($this->conn->runSingle($query, [$employeeId]));
    }

    public function checkEditDuplicateM365Admin($employeeId, $id) {
        $query = 'SELECT 1 FROM tbl_m365_admins WHERE employee_id = ? AND id != ?';
        $this->logger->logQuery($query, [$employeeId, $id], 'classes');
        return !empty($this->conn->runSingle($query, [$employeeId, $id]));
    }
}

