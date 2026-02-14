<?php
/*  Table structure for ams_assignment_type:
    CREATE TABLE ams_assignment_type (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_type VARCHAR(25) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

*/

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


class AssignmentTypes
{
    private $conn;
    private $logger;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    // get all assignment types
    public function getAllAssignmentTypes($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_assignment_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all assignment types: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // assignment types combo
    public function getAssignmentTypesCombo($module, $username)
    {
        try {
            $query = 'SELECT id, assignment_type FROM ams_assignment_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching assignment types combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get assignment type by id
    public function getAssignmentTypeById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_assignment_type WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            return $this->conn->runQuery($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error fetching assignment type by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get assignment type count
    public function getAssignmentTypesCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS count FROM ams_assignment_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runSingle($query);
            return $result ? $result['count'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching assignment type count: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get paginated assignment types
    public function getPaginatedAssignmentTypes($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT * FROM ams_assignment_type ORDER BY id DESC LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated assignment types: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert assignment type 
    public function insertAssignmentType($assignmentType, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_assignment_type (assignment_type, created_by) VALUES (?, ?)';
            $params = [$assignmentType, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Assignment type '$assignmentType' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting assignment type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch assignment types from excel
    public function insertBatchAssignmentTypesFromExcel($assignmentTypes, $createdBy)
    {
        if (empty($assignmentTypes) || !is_array($assignmentTypes)) {
            $this->logger->log('No assignment types to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_assignment_type (assignment_type, created_by) VALUES (?, ?)';
            $params = [];
            foreach ($assignmentTypes as $assignmentType) {
                $params[] = [$assignmentType, $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch assignment types from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    public function getExistingAssignmentTypesByNames(array $assignmentTypesLower, $module, $username)
    {
        if (empty($assignmentTypesLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assignmentTypesLower), '?'));
            $query = "SELECT LOWER(assignment_type) AS assignment_type FROM ams_assignment_type WHERE LOWER(assignment_type) IN ($placeholders)";
            $this->logger->logQuery($query, $assignmentTypesLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assignmentTypesLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['assignment_type']) ? strtolower($row['assignment_type']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing assignment types: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update assignment type 
    public function updateAssignmentType($id, $assignmentType, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_assignment_type SET assignment_type = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assignmentType, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Assignment type ID $id updated to '$assignmentType' by user ID $lastUpdatedBy";
            return $this->conn->update($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error updating assignment type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete assignment type
    public function deleteAssignmentType($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_assignment_type WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Assignment type ID $id deleted by user $username";
            return $this->conn->delete($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting assignment type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate assignment type for insert
    public function isDuplicateAssignmentType($assignmentType, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_assignment_type WHERE assignment_type = ?';
            $params = [$assignmentType];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate assignment type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate assignment type for update
    public function isDuplicateAssignmentTypeForUpdate($id, $assignmentType, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_assignment_type WHERE assignment_type = ? AND id != ?';
            $params = [$assignmentType, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate assignment type for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}

?>