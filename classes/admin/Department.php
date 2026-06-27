<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';

class Department
{

    private $conn;
    private $logger;
    private $excelHelper;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
        $this->excelHelper = new ExcelHelper(__DIR__ . '/../../excel-config/admin/department.ini');
    }

    public function getAllDepartments($module, $username)
    {
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

    public function getPaginatedDepartments($offset, $limit, $module, $username)
    {
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

    public function getDepartmentsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM tbl_department';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getDepartmentById($id, $module, $username)
    {
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

    public function getDepartmentByCode($code, $module, $username)
    {
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

    public function insertDepartment($name, $code, $status, $module, $username)
    {
        $query = 'INSERT INTO tbl_department (name, code, status) VALUES (?, ?, ?)';
        $this->logger->logQuery($query, [$name, $code, $status], 'classes', $module, $username);
        $logMessage = 'Department Inserted ';
        return $this->conn->insert($query, [$name, $code, $status], $logMessage);
    }

    public function insertDepartmentsFromExcel($batchId, $username)
    {
        $tempTableRows = $this->excelHelper->selectTemporaryTableRows($batchId);
        $tableName = $this->excelHelper->getMainTableName();
        if (empty($tempTableRows)) {
            throw new Exception("No data found in temporary table for batch id: $batchId");
        }

        $rowNumber = 1; // Assuming the first row is the header
        $cleanedRows = [];
        $duplicateRowsInExcelFile = [];
        foreach ($tempTableRows as $row) {
            $key = $row['name'] . '_' . $row['code'];
            if ($row['name'] === '' || $row['code'] === '' || $row['status'] === '') {
                $duplicateRowsInExcelFile[] = ['Error' => "Row $rowNumber has empty fields. Name, Code, and Status are required."];
            } else if (isset($cleanedRows[$key])) {
                $duplicateRowsInExcelFile[] = ['row_number' => $rowNumber, 'data' => ['name' => $row['name'], 'code' => $row['code']]];
            } else {
                $cleanedRows[$key] = $row;
            }
            $rowNumber++;
        }
        $duplicateRowsInDb = [];

        // get the existing rows from the main table to check for duplicates
        $existingRows = $this->conn->runQuery("SELECT name, code FROM $tableName");
        $existingRowsMap = [];

        foreach ($existingRows as $existingRow) {
            $key = strtolower($existingRow['name']) . '_' . $existingRow['code'];
            $existingRowsMap[$key] = true;
        }

        foreach ($cleanedRows as $row) {
            // validate the data
            if ($row['status'] !== 'Active' && $row['status'] !== 'In-active' && $row['status'] !== 'Suspended' && $row['status'] !== 'Blocked') {
                throw new Exception("Invalid value for status: " . $row['status']);
            }
            // map the status to the corresponding id in the tbl_status table
            $statusId = $this->getStatusId(strtolower($row['status']));
            $row['status'] = $statusId;

            // check for duplicates in the main table, if found, skip the insertion and log the duplicate
            if (isset($existingRowsMap[strtolower($row['name']) . '_' . $row['code']])) {
                // Log the duplicate row
                $duplicateRowsInDb[] = ['name' => $row['name'], 'code' => $row['code']];
                continue; // Skip this row
            }

            // insert the data into the table
            $this->conn->runQuery("INSERT INTO $tableName (name, code, status, createdBy) VALUES (:name, :code, :status, :createdBy)", [
                ":name" => trim($row['name']),
                ":code" => trim($row['code']),
                ":status" => intval($row['status']),
                ":createdBy" => $username
            ]);
        }

        return $this->excelHelper->generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb);
    }

    public function getStatusId($status)
    {
        $query = "SELECT id FROM tbl_status WHERE lower(status) = ? AND module = 'GEN'";
        $result = $this->conn->runSingle($query, [$status]);
        if ($result && isset($result['id'])) {
            return $result['id'];
        }
        throw new Exception("Status not found: " . $status);
    }

    public function updateDepartment($id, $name, $code, $status, $module, $username)
    {
        $query = 'UPDATE tbl_department SET name = ?, code = ?, status = ? WHERE id = ?';
        $this->logger->logQuery($query, [$name, $code, $status, $id], 'classes', $module, $username);
        return $this->conn->update($query, [$name, $code, $status, $id]);
    }

    public function deleteDepartment($id, $module, $username)
    {
        $query = 'DELETE FROM tbl_department WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->update($query, [$id]);
    }
}
