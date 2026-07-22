<?php

// Table structure for table `tbl_salary`
// CREATE TABLE tbl_salary (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     employee_id INT NOT NULL,
//     gross DECIMAL(10, 2) NOT NULL,
//     increment DECIMAL(10, 2) NOT NULL,
//     effective_from DATE NOT NULL,
//     effective_to DATE NOT NULL,
//     createdBy INT NOT NULL,
//     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//     updatedBy INT NOT NULL,
//     updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/LookupCache.php';

class Salaries
{
    private $conn;
    private $logger;
    private $salaryExcelHelper;
    private $incrementsExcelHelper;
    // private $lookupCache;


    private static $commonSalaryQuery = "SELECT 
                        concat(cont.f_name, ' ', cont.l_name) AS employee_name, 
                        emp.id AS employee_id,
                        emp.emp_code, emp.old_emp_code, 
                        us.email, (sal.gross + sal.increment) AS gross_salary, 
                        sal.id AS salary_id, 
                        sal.effective_from, sal.effective_to 
                        FROM tbl_salary sal 
                        JOIN tbl_employee emp ON emp.id = sal.employee_id 
                        JOIN tbl_users us ON us.id = emp.user_id 
                        JOIN tbl_contact cont ON cont.id = emp.contact_id";

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
        $this->salaryExcelHelper = new ExcelHelper(__DIR__ . '/../../excel-config/hr/salaries.ini');
        $this->incrementsExcelHelper = new ExcelHelper(__DIR__ . '/../../excel-config/hr/increments.ini');
        // $this->lookupCache = new LookupCache($this->conn, $this->logger);
        // $this->lookupCache->load();
    }

    // get paginated salaries
    public function getPaginatedSalaries($offset, $limit, $module, $username)
    {
        $limit = $limit ?? 10;
        $offset = $offset ?? 1;

        $query = self::$commonSalaryQuery . " ORDER BY sal.effective_from DESC LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query, []);
    }

    // get salaries count
    public function getSalariesCount($module, $username)
    {
        $query = "SELECT COUNT(*) FROM tbl_salary";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, []);
        return $result['COUNT(*)'] ?? 0;
    }

    // get current effective from date
    public function getCurrentEffectiveFromDate($module, $username)
    {
        $query = "SELECT MAX(effective_from) FROM tbl_salary";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, []);
        return $result['MAX(effective_from)'] ?? null;
    }

    // get salary by id
    public function getSalaryById($id)
    {
        $query = self::$commonSalaryQuery . " WHERE sal.id = ? ORDER BY sal.effective_from DESC";
        $this->logger->logQuery($query, [$id]);
        return $this->conn->runQuery($query, [$id]);
    }

    // get salary by employee id
    public function getSalaryByEmployeeId($employeeId)
    {
        $query = self::$commonSalaryQuery . " WHERE sal.employee_id = ? ORDER BY sal.effective_from DESC LIMIT 1";
        $this->logger->logQuery($query, [$employeeId]);
        return $this->conn->runQuery($query, [$employeeId]);
    }

    // get salary by employee code
    public function getSalaryByEmployeeCode($employeeCode)
    {
        $query = self::$commonSalaryQuery . " WHERE emp.emp_code = ? ORDER BY sal.effective_from DESC";
        $this->logger->logQuery($query, [$employeeCode]);
        return $this->conn->runQuery($query, [$employeeCode]);
    }

    // get salary by olde employee code
    public function getSalaryByOldEmployeeCode($oldEmployeeCode)
    {
        $query = self::$commonSalaryQuery . " WHERE emp.old_emp_code = ? ORDER BY sal.effective_from DESC";
        $this->logger->logQuery($query, [$oldEmployeeCode]);
        return $this->conn->runQuery($query, [$oldEmployeeCode]);
    }

    // get salary by effective from and effective to
    public function getSalaryByEffectiveFromAndEffectiveTo($effectiveFrom, $effectiveTo)
    {
        $query = self::$commonSalaryQuery . " WHERE sal.effective_from = ? AND sal.effective_to = ? ORDER BY sal.effective_from DESC";
        $this->logger->logQuery($query, [$effectiveFrom, $effectiveTo]);
        return $this->conn->runQuery($query, [$effectiveFrom, $effectiveTo]);
    }

    // get latest salary by employee id
    public function getLatestSalaryByEmployeeId($employeeId)
    {
        $query = self::$commonSalaryQuery . " WHERE sal.employee_id = ? ORDER BY sal.effective_from DESC LIMIT 1";
        $this->logger->logQuery($query, [$employeeId]);
        return $this->conn->runQuery($query, [$employeeId]);
    }

    // get latest salary by employee code
    public function getLatestSalaryByEmployeeCode($employeeCode)
    {
        $query = self::$commonSalaryQuery . " WHERE emp.emp_code = ? ORDER BY sal.effective_from DESC LIMIT 1";
        $this->logger->logQuery($query, [$employeeCode]);
        return $this->conn->runQuery($query, [$employeeCode]);
    }

    // get latest salary by old employee code
    public function getLatestSalaryByOldEmployeeCode($oldEmployeeCode)
    {
        $query = self::$commonSalaryQuery . " WHERE emp.old_emp_code = ? ORDER BY sal.effective_from DESC LIMIT 1";
        $this->logger->logQuery($query, [$oldEmployeeCode]);
        return $this->conn->runQuery($query, [$oldEmployeeCode]);
    }
    // create salary 
    public function createSalary($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $module, $username)
    {
        $query = "INSERT INTO tbl_salary 
                (employee_id, gross, increment, effective_from, effective_to, createdBy) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $this->logger->logQuery($query, [$employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $username]);
        return $this->conn->insert($query, [$employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $username]);
    }

    // update salary
    public function updateSalary($id, $employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $module, $username)
    {
        $query = "UPDATE tbl_salary SET employee_id = ?, gross = ?, increment = ?, effective_from = ?, effective_to = ?, updatedBy = ? WHERE id = ?";
        $this->logger->logQuery($query, [$employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $username, $id], 'classes', $module);
        return $this->conn->update($query, [$employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $username, $id]);
    }

    // change the salary effective date
    public function addNewSalary($employeeId, $gross, $increment, $effectiveFrom, $module, $username)
    {
        // first check if the salary exists
        // if not exists, then create a new salary
        $salary = $this->getSalaryByEmployeeId($employeeId);
        if (empty($salary)) {
            $effectiveTo = null;
            $insertedId = $this->createSalary($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $module, $username);
            if (empty($insertedId)) {
                return [
                    'success' => false,
                    'message' => 'Failed to create a new salary',
                    'data' => []
                ];
            }
            return [
                'success' => true,
                'message' => 'Salary created successfully',
                'data' => [
                    'salary_id' => $insertedId
                ]
            ];
        }

        // get the latest salary for the employee
        $latestSalary = $this->getLatestSalaryByEmployeeId(intval($salary[0]['employee_id']));
        if (empty($latestSalary)) {
            return [
                'success' => false,
                'message' => 'Latest salary not found',
                'data' => []
            ];
        }
        // change the previous salary effective to date to the effective from date
        $updateSalaryQuery = "UPDATE tbl_salary SET effective_to = ? WHERE id = ?";
        $this->logger->logQuery($updateSalaryQuery, [$effectiveFrom, $latestSalary[0]['salary_id']]);
        $updatedSalary = $this->conn->update($updateSalaryQuery, [$effectiveFrom, $latestSalary[0]['salary_id']]);
        if (empty($updatedSalary)) {
            return [
                'success' => false,
                'message' => 'Failed to change the salary effective date',
                'data' => []
            ];
        }
        $effectiveTo = null;

        // calculate the new gross (gross + increment of the previous salary)
        $newGross = floatval($latestSalary[0]['gross_salary']);
        // create a new salary with the effective from date and the effective to date
        $insertedId = $this->createSalary($employeeId, $newGross, $increment, $effectiveFrom, $effectiveTo, $module, $username);
        if (empty($insertedId)) {
            return [
                'success' => false,
                'message' => 'Failed to create a new salary',
                'data' => []
            ];
        }
        return [
            'success' => true,
            'message' => 'Salary effective date changed successfully',
            'data' => [
                'salary_id' => $insertedId
            ]
        ];
    }

    // import salaries from excel 
    // 
    public function importSalariesFromExcel($batchId, $module, $username)
    {
        $rows = $this->salaryExcelHelper->selectTemporaryTableRows($batchId, $module);
        $tableName = $this->salaryExcelHelper->getMainTableName();
        if (empty($rows)) {
            throw new Exception('No data found in temporary table for batch id: ' . $batchId);
        }

        // find the duplicatesInExcelFile array

        $rowNumber = 1;
        $cleanedRows = [];
        $duplicateRowsInExcelFile = [];
        foreach ($rows as $row) {
            // if the row is empty, then add the error to the duplicateRowsInExcelFile array and continue to the next row
            if (empty($row['old_emp_code']) || empty($row['gross']) || empty($row['increment']) || empty($row['effective_from'])) {
                $duplicateRowsInExcelFile[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Invalid row data',
                    'data' => [
                        'Old Employee Code' => $row['old_emp_code'],
                    ]
                ];
                $rowNumber++;
                continue;
            }
            // if the row is not empty, then check if the old employee code is already in the cleanedRows array
            $key = strtolower($row['old_emp_code']);
            if (isset($cleanedRows[$key])) {
                $duplicateRowsInExcelFile[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Duplicate row found',
                    'data' => [
                        'Old Employee Code' => $row['old_emp_code'],
                    ]
                ];
                $rowNumber++;
                continue;
            }
            $cleanedRows[$key] = $row;
            $rowNumber++;
        }

        // now loop through the cleanedRows array and check against the main table to check for duplicates
        $duplicateRowsInDb = [];
        // no rows with the old employee code and same effective from date already exists
        $exstingRows = $this->conn->runQuery("SELECT emp.old_emp_code AS old_emp_code, sal.effective_from AS effective_from FROM $tableName sal
                                JOIN tbl_employee emp ON emp.id = sal.employee_id");
        $exstingRowsMap = [];

        foreach ($exstingRows as $exstingRow) {
            $oldEmpCode = trim(explode('-', $exstingRow['old_emp_code'])[0]);
            $key = strtolower($oldEmpCode) . '_' . strtolower($exstingRow['effective_from']);

            $exstingRowsMap[$key] = true;
        }

        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        foreach ($cleanedRows as $row) {
            // old emp code is like CODE - Employee Name, get S002 from it
            $oldEmpCode = trim(explode('-', $row['old_emp_code'])[0]);
            $key = strtolower($oldEmpCode) . '_' . strtolower($row['effective_from']);
            if (isset($exstingRowsMap[$key])) {
                $duplicateRowsInDb[] = [
                    'Error' => 'Duplicate row found',
                    'data' => [
                        'Old Employee Code' => $row['old_emp_code'],
                    ]
                ];
                continue;
            }
            $employeeId = $lookupCache->getEmployeeIdByOldEmployeeCode(strtolower($oldEmpCode));
            if (!$employeeId) {
                throw new Exception('Employee not found: ' . $row['old_emp_code']);
            }

            
            $effectiveFrom = date('Y-m-d', strtotime($row['effective_from']) ?? null);

            $this->addNewSalary($employeeId, $row['gross'], $row['increment'], $effectiveFrom, $module, $username);
        }
        return $this->salaryExcelHelper->generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb);
    }

    public function importIncrementsFromExcel($batchId, $module, $username)
    {
        $rows = $this->incrementsExcelHelper->selectTemporaryTableRows($batchId, $module);
        $tableName = $this->incrementsExcelHelper->getMainTableName();
        if (empty($rows)) {
            throw new Exception('No data found in temporary table for batch id: ' . $batchId);
        }

        $rowNumber = 1;
        $duplicateRowsInExcelFile = [];
        $cleanedRows = [];
        foreach ($rows as $row) {
            // if the increment is 0 or empty, then skip the row because HR dont wish to increment that employee
            if (empty($row['increment']) || intval($row['increment']) == 0) {
                $rowNumber++;
                continue;
            }
            // if the employee is not empty, then check if the employee is already in the cleanedRows array
            $key = strtolower($row['employee']);
            if (isset($cleanedRows[$key])) {
                $duplicateRowsInExcelFile[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Duplicate row found',
                    'data' => [
                        'Employee' => $row['employee'],
                    ]
                ];
                $rowNumber++;
                continue;
            }
            $cleanedRows[$key] = $row;
            $rowNumber++;
            }

        // duplicate rows in db
        $duplicateRowsInDb = [];

        
        // if the same employee has same effective from date already exists, then add the error to the duplicateRowsInDb array
        $existingRows = $this->conn->runQuery("SELECT emp.old_emp_code, sal.effective_from FROM $tableName sal JOIN tbl_employee emp ON emp.id = sal.employee_id");
        $existingRowsMap = [];
        foreach ($existingRows as $existingRow) {
            $key = strtolower($existingRow['old_emp_code']) . '_' . strtolower($existingRow['effective_from']);
            $existingRowsMap[$key] = true;
        }

        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        foreach ($cleanedRows as $row) {
            $oldEmpCode = trim(explode('-', $row['employee'])[0]);
            $key = strtolower($oldEmpCode) . '_' . strtolower($row['effective_from']);
            if (isset($existingRowsMap[$key])) {
                $duplicateRowsInDb[] = [
                    'Error' => 'Duplicate row found',
                    'data' => [
                        'Employee' => $row['employee'],
                    ]
                ];
                continue;
            }

            $employeeId = $lookupCache->getEmployeeIdByOldEmployeeCode(strtolower($oldEmpCode));
            if (!$employeeId) {
                throw new Exception('Employee not found: ' . $row['old_emp_code']);
            }
            
            $employeeId = intval($employeeId);
            $effectiveFrom = date('Y-m-d', strtotime($row['effective_from']));
            $gross = $row['gross'];
            $increment = intval($row['increment']);
            $this->addNewSalary($employeeId, $gross, $increment, $effectiveFrom, $module, $username);
        }
        return $this->incrementsExcelHelper->generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb);
    }
}
