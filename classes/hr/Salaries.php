<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/LookupCache.php';

class Salaries
{
    private const ACTIVE_STATUS = 1;
    private const INACTIVE_STATUS = 2;

    private $conn;
    private $logger;
    private $excelHelper;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE'])
            && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
        $this->excelHelper = new ExcelHelper($_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/salaries.ini');
    }

    private function salarySelect(): string
    {
        return 'SELECT
					sal.id,
					sal.employee_id,
					emp.emp_code,
					CONCAT(cont.f_name, \' \', cont.l_name) AS employee_name,
                    (sal.gross - sal.increment) AS gross,
					sal.gross as total_gross,
					sal.increment,
					sal.effective_from,
					sal.effective_to,
					sal.revision_type,
					sal.status AS status_id,
					CASE
						WHEN sal.status = 1 THEN \'ACTIVE\'
						WHEN sal.status = 2 THEN \'INACTIVE\'
						ELSE \'UNKNOWN\'
					END AS status,
					sal.notes
				FROM tbl_salary sal
				JOIN tbl_employee emp ON emp.id = sal.employee_id
                LEFT JOIN tbl_contact cont ON cont.id = emp.contact_id';
    }

    public function getAllSalaries($module, $username)
    {
        $query = $this->salarySelect() . ' ORDER BY sal.effective_from DESC, sal.id DESC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedSalaries($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $offset);
        $query = $this->salarySelect()
            . ' WHERE sal.status = ?'
            . " ORDER BY sal.effective_from DESC, sal.id DESC LIMIT $limit OFFSET $offset";
        $params = [self::ACTIVE_STATUS];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getSalariesByEmployeeId($employeeId, $module, $username)
    {
        $query = $this->salarySelect()
            . ' WHERE sal.employee_id = ? ORDER BY sal.effective_from DESC, sal.id DESC';
        $params = [$employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getActiveSalaryByEmployeeId($employeeId, $module, $username)
    {
        $query = $this->salarySelect()
            . ' WHERE sal.employee_id = ? AND sal.status = ?'
            . ' ORDER BY sal.effective_from DESC, sal.id DESC LIMIT 1';
        $params = [$employeeId, self::ACTIVE_STATUS];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runSingle($query, $params);
    }

    public function getSalariesCountByEmployeeId($employeeId, $module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM tbl_salary WHERE employee_id = ?';
        $params = [$employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runSingle($query, $params);
        return isset($result['total']) ? (int) $result['total'] : 0;
    }

    public function addSalaryRecord(
        $employeeId,
        $gross,
        $increment,
        $effectiveFrom,
        $revisionType,
        $notes,
        $createdBy,
        $module,
        $username,
        $status = self::ACTIVE_STATUS
    ) {
        $this->validateStatus($status);
        $status = self::ACTIVE_STATUS;
        $revisionType = $this->normalizeRevisionType($revisionType);
        // effective_to is never accepted from the caller; closeActiveSalaryIfRequired()
        // is the only place that sets it, using new effective_from - 1 day.
        $effectiveTo = null;
        $this->validateSalaryValues($employeeId, $gross, $increment, $effectiveFrom, $revisionType, $notes);

        if (!$this->employeeExists($employeeId, $module, $username)) {
            throw new InvalidArgumentException('Employee with ID ' . $employeeId . ' does not exist.');
        }
        if ($this->checkDuplicateSalaryRecord($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $module, $username)) {
            throw new InvalidArgumentException('Duplicate salary record already exists.');
        }
        if ($revisionType === 'INITIAL' && $this->hasInitialSalary($employeeId, $module, $username)) {
            throw new InvalidArgumentException('An INITIAL salary already exists for this employee.');
        }

        $activeSalary = $this->getActiveSalaryByEmployeeId($employeeId, $module, $username);
        if ($revisionType === 'INITIAL' && $activeSalary) {
            throw new InvalidArgumentException('An active salary already exists for this employee.');
        }

        if ($revisionType === 'INCREMENT') {
            if (!$activeSalary || !$this->hasInitialSalary($employeeId, $module, $username)) {
                throw new InvalidArgumentException('An INITIAL and active salary are required before creating an increment.');
            }
            $expectedGross = bcadd((string) $activeSalary['total_gross'], (string) $increment, 2);
            if (bccomp((string) $gross, $expectedGross, 2) !== 0) {
                throw new InvalidArgumentException('Gross must equal the active gross plus the increment.');
            }
        }
        if ($revisionType === 'ADJUSTMENT') {
            if (!$activeSalary) {
                throw new InvalidArgumentException('An active salary is required before creating an adjustment.');
            }
            $previousRevisionType = strtoupper((string) $activeSalary['revision_type']);
            if ($previousRevisionType !== 'INITIAL') {
                throw new InvalidArgumentException('An adjustment is only allowed when the active salary is an INITIAL revision.');
            }
            if ($increment === null || bccomp((string) $increment, '0', 2) !== 0) {
                throw new InvalidArgumentException('Increment must be 0 when adjusting an INITIAL revision; only gross can be changed.');
            }
        }

        $this->closeActiveSalaryIfRequired($activeSalary, $effectiveFrom, $createdBy, $module, $username);

        $query = 'INSERT INTO tbl_salary
			(employee_id, gross, increment, effective_from, effective_to,
			 revision_type, status, notes, createdBy, updatedBy)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $params = [$employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $status, $notes, $createdBy, $createdBy];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $salaryId = $this->conn->insert($query, $params, 'Salary record inserted ');

        return ['success' => true, 'message' => 'Salary record created successfully.', 'data' => ['salary_id' => $salaryId]];
    }

    public function updateSalaryRecord(
        $id,
        $employeeId,
        $gross,
        $increment,
        $effectiveFrom,
        $effectiveTo,
        $revisionType,
        $notes,
        $updatedBy,
        $module,
        $username
    ) {
        $revisionType = $this->normalizeRevisionType($revisionType);
        $this->validateSalaryValues($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $notes);

        $existingQuery = 'SELECT id, status FROM tbl_salary WHERE id = ?';
        $existingParams = [$id];
        $this->logger->logQuery($existingQuery, $existingParams, 'classes', $module, $username);
        $existing = $this->conn->runSingle($existingQuery, $existingParams);
        if (!$existing) {
            throw new InvalidArgumentException('Salary record with ID ' . $id . ' does not exist.');
        }
        $status = $this->validateStatus($existing['status']);
        if ($this->checkEditDuplicateSalaryRecord($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $id, $module, $username)) {
            throw new InvalidArgumentException('Duplicate salary record already exists.');
        }

        $query = 'UPDATE tbl_salary SET employee_id = ?, gross = ?, increment = ?, effective_from = ?,
			effective_to = ?, revision_type = ?, status = ?, notes = ?, updatedBy = ? WHERE id = ?';
        $params = [$employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $status, $notes, $updatedBy, $id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $affectedRows = $this->conn->update($query, $params, 'Salary record updated ');

        return ['success' => true, 'message' => 'Salary record updated successfully.', 'data' => ['affected_rows' => $affectedRows]];
    }

    public function checkDuplicateSalaryRecord($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $module = 'system', $username = 'system')
    {
        $query = 'SELECT 1 FROM tbl_salary WHERE employee_id = ? AND gross = ?
			AND ((increment = ?) OR (increment IS NULL AND ? IS NULL))
			AND effective_from = ? AND ((effective_to = ?) OR (effective_to IS NULL AND ? IS NULL))
			AND LOWER(TRIM(revision_type)) = LOWER(TRIM(?)) LIMIT 1';
        $params = [$employeeId, $gross, $increment, $increment, $effectiveFrom, $effectiveTo, $effectiveTo, $revisionType];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return (bool) $this->conn->runSingle($query, $params);
    }

    public function checkEditDuplicateSalaryRecord($employeeId, $gross, $increment, $effectiveFrom, $effectiveTo, $revisionType, $id, $module = 'system', $username = 'system')
    {
        $query = 'SELECT 1 FROM tbl_salary WHERE employee_id = ? AND gross = ?
			AND ((increment = ?) OR (increment IS NULL AND ? IS NULL))
			AND effective_from = ? AND ((effective_to = ?) OR (effective_to IS NULL AND ? IS NULL))
			AND LOWER(TRIM(revision_type)) = LOWER(TRIM(?)) AND id != ? LIMIT 1';
        $params = [$employeeId, $gross, $increment, $increment, $effectiveFrom, $effectiveTo, $effectiveTo, $revisionType, $id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return (bool) $this->conn->runSingle($query, $params);
    }

    private function employeeExists($employeeId, $module, $username): bool
    {
        $query = 'SELECT 1 FROM tbl_employee WHERE id = ?';
        $params = [$employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return (bool) $this->conn->runSingle($query, $params);
    }

    private function hasInitialSalary($employeeId, $module, $username): bool
    {
        $query = "SELECT 1 FROM tbl_salary WHERE employee_id = ? AND LOWER(TRIM(revision_type)) = 'initial' LIMIT 1";
        $params = [$employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return (bool) $this->conn->runSingle($query, $params);
    }

    private function closeActiveSalaryIfRequired($activeSalary, $effectiveFrom, $updatedBy, $module, $username): void
    {
        if (!$activeSalary) {
            return;
        }
        // Close the active salary if its effective period overlaps with the new effective_from date
        if ($activeSalary['effective_to'] === null || $activeSalary['effective_to'] >= $effectiveFrom) {
            // Determine the close date for the active salary, which is one day before the new effective_from date
            $closeDate = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));
            // if the close date is before the active salary's effective_from date, it's an invalid period
            if ($closeDate < $activeSalary['effective_from']) {
                throw new InvalidArgumentException('The new effective date creates an invalid salary period.');
            }
            $query = 'UPDATE tbl_salary SET status = ?, effective_to = ?, updatedBy = ? WHERE id = ?';
            $params = [self::INACTIVE_STATUS, $closeDate, $updatedBy, $activeSalary['id']];
        } else {
            $query = 'UPDATE tbl_salary SET status = ?, updatedBy = ? WHERE id = ?';
            $params = [self::INACTIVE_STATUS, $updatedBy, $activeSalary['id']];
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $this->conn->update($query, $params, 'Previous salary record made inactive ');
    }

    private function validateStatus($status): int
    {
        $status = (int) $status;
        if (!in_array($status, [self::ACTIVE_STATUS, self::INACTIVE_STATUS], true)) {
            throw new InvalidArgumentException('Salary status must be either 1 (Active) or 2 (In-Active).');
        }
        return $status;
    }

    private function normalizeRevisionType($revisionType): string
    {
        $revisionType = strtoupper(trim((string) $revisionType));
        if (!in_array($revisionType, ['INITIAL', 'INCREMENT', 'ADJUSTMENT'], true)) {
            throw new InvalidArgumentException('Revision type must be INITIAL, INCREMENT, or ADJUSTMENT.');
        }
        return $revisionType;
    }

    private function validateSalaryValues($employeeId, $gross, &$increment, &$effectiveFrom, $revisionType, $notes): void
    {
        if (!is_numeric($employeeId) || (int) $employeeId <= 0) {
            throw new InvalidArgumentException('A valid employee ID is required.');
        }
        if (!is_numeric($gross) || bccomp((string) $gross, '0', 2) < 0) {
            throw new InvalidArgumentException('Gross must be a valid non-negative amount.');
        }
        if ($increment !== null && $increment !== '' && !is_numeric($increment)) {
            throw new InvalidArgumentException('Increment must be a valid numeric amount.');
        }
        if ($increment === '') {
            $increment = null;
        }
        if ($revisionType === 'INITIAL') {
            $increment = null;
        }
        if ($revisionType === 'INCREMENT' && ($increment === null || bccomp((string) $increment, '0', 2) <= 0)) {
            throw new InvalidArgumentException('Increment must be greater than zero for an INCREMENT revision.');
        }
        if ($revisionType === 'ADJUSTMENT' && ($notes === null || trim((string) $notes) === '')) {
            throw new InvalidArgumentException('Notes are required for an ADJUSTMENT revision.');
        }
        


        if (!$this->isValidDate($effectiveFrom)) {
            throw new InvalidArgumentException('Effective-from must be a valid date in YYYY-MM-DD format.');
        }
        if ($revisionType === 'INCREMENT' && substr($effectiveFrom, 8, 2) !== '01') {
            throw new InvalidArgumentException('Effective-from must be the first day of the month for an INCREMENT revision.');
        }
    }

    private function isValidDate($date): bool
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', (string) $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    // Import salaries from excel file (intial dump of salary data - one-time operation)
    public function importSalariesFromExcel($batchId, $module, $username)
    {
        $rows = $this->excelHelper->selectTemporaryTableRows($batchId);
        if (empty($rows)) {
            throw new Exception('No data found in temporary table for batch id: ' . $batchId);
        }

        $duplicateRowsInExcel = [];
        $duplicateRowsInDb = [];
        $cleanedRows = [];
        $seenEmployeeCodes = [];
        $rowNumber = 2;

        foreach ($rows as $row) {
            $employeeCode = strtolower(trim((string) ($row['old_emp_code'] ?? '')));
            $gross = trim((string) ($row['gross'] ?? ''));
            $effectiveFrom = trim((string) ($row['effective_from'] ?? ''));

            if ($employeeCode === '' || $gross === '' || $effectiveFrom === '' || !is_numeric($gross) || (float) $gross <= 0) {
                $duplicateRowsInExcel[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Row has missing or invalid employee code, gross, or effective-from date.',
                    'data' => [
                        'old_emp_code' => $row['old_emp_code'] ?? null,
                        'gross' => $row['gross'] ?? null,
                        'effective_from' => $row['effective_from'] ?? null,
                    ],
                ];
                $rowNumber++;
                continue;
            }

            if (isset($seenEmployeeCodes[$employeeCode])) {
                $duplicateRowsInExcel[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Row is a duplicate for the same employee code.',
                    'data' => [
                        'old_emp_code' => $row['old_emp_code'],
                    ],
                ];
                $rowNumber++;
                continue;
            }

            $seenEmployeeCodes[$employeeCode] = $rowNumber;
            $cleanedRows[] = [
                'row_number' => $rowNumber,
                'data' => $row,
            ];
            $rowNumber++;
        }

        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        $existingSalaryQuery = "SELECT LOWER(TRIM(emp.old_emp_code)) AS old_emp_code
                                    FROM tbl_salary sal
                                    JOIN tbl_employee emp ON emp.id = sal.employee_id
                                    WHERE LOWER(TRIM(sal.revision_type)) = 'initial' AND emp.emp_status = 1";
        $this->logger->logQuery($existingSalaryQuery, [], 'classes', $module, $username);
        $existingSalaryRows = $this->conn->runQuery($existingSalaryQuery);
        $existingSalaryCodes = [];
        foreach ($existingSalaryRows as $existingSalaryRow) {
            $existingSalaryCodes[$existingSalaryRow['old_emp_code']] = true;
        }

        // process the cleaned rows
        foreach ($cleanedRows as $cleanedRow) {
            $row = $cleanedRow['data'];
            $rowNumber = $cleanedRow['row_number'];
            $employeeCode = strtolower(trim($row['old_emp_code']));

            // get the employee id from the lookup cache using the old employee code
            $employeeId = intval($lookupCache->getEmployeeIdByOldEmployeeCode($row['old_emp_code']));
            if ($employeeId <= 0) {
                $duplicateRowsInDb[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Employee not found in database.',
                    'data' => [
                        'old_emp_code' => $row['old_emp_code'],
                    ],
                ];
                continue;
            }

            if (isset($existingSalaryCodes[$employeeCode])) {
                $duplicateRowsInDb[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'An INITIAL salary already exists for this employee.',
                    'data' => [
                        'old_emp_code' => $row['old_emp_code'],
                    ],
                ];
                continue;
            }

            $this->addSalaryForExcelImport(
                $employeeId,
                $row['effective_from'],
                $row['gross'],
                $module,
                $username
            );
        }

        return $this->excelHelper->generateErrorReport($duplicateRowsInExcel, $duplicateRowsInDb);
    }


    // Import increment salary records from Excel - This function will handle adding increment salary records for employees based on the data imported from an Excel file.
    public function importIncrementsFromExcel($batchId, $module, $username)
    {
        $rows = $this->excelHelper->selectTemporaryTableRows($batchId);
        if (empty($rows)) {
            throw new Exception('No data found in temporary table for batch id: ' . $batchId);
        }

        $duplicateRowsInExcel = [];
        $duplicateRowsInDb = [];
        $cleanedRows = [];
        $seenEmployeePairs = [];
        $rowNumber = 2;

        foreach ($rows as $row) {
            $oldEmployeeCode = trim((string) ($row['old_employee_code'] ?? ''));
            $newEmployeeCode = trim((string) ($row['new_employee_code'] ?? ''));
            $duplicateKey = strtolower($oldEmployeeCode) . '|' . strtolower($newEmployeeCode);

            if ($oldEmployeeCode === '' || $newEmployeeCode === '') {
                $duplicateRowsInExcel[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Old employee code and new employee code are required.',
                    'data' => [
                        'old_employee_code' => $oldEmployeeCode,
                        'new_employee_code' => $newEmployeeCode,
                    ],
                ];
                $rowNumber++;
                continue;
            }

            if (isset($seenEmployeePairs[$duplicateKey])) {
                $duplicateRowsInExcel[] = [
                    'row_number' => $rowNumber,
                    'Error' => 'Row is a duplicate for the same old and new employee code.',
                    'data' => [
                        'old_employee_code' => $oldEmployeeCode,
                        'new_employee_code' => $newEmployeeCode,
                    ],
                ];
                $rowNumber++;
                continue;
            }

            $seenEmployeePairs[$duplicateKey] = $rowNumber;
            $cleanedRows[] = ['row_number' => $rowNumber, 'data' => $row];
            $rowNumber++;
        }

        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        foreach ($cleanedRows as $cleanedRow) {
            $row = $cleanedRow['data'];
            $rowNumber = $cleanedRow['row_number'];
            $oldEmployeeCode = trim((string) $row['old_employee_code']);
            $newEmployeeCode = trim((string) $row['new_employee_code']);
            $currentGross = trim((string) ($row['gross'] ?? ''));
            $increment = trim((string) ($row['increment'] ?? ''));
            $newEffectiveFrom = trim((string) ($row['new_effective_from'] ?? ''));

            if ($currentGross === '' || !is_numeric($currentGross)) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'Current gross is missing or invalid.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }
            // increment can be null or zero, but if provided, it must be greater than zero
            if ($increment !== '' && (!is_numeric($increment) || bccomp($increment, '0', 2) <= 0)) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'Increment must be greater than zero if provided.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            if (!$this->isValidDate($newEffectiveFrom)) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'New effective-from date is required and must be in YYYY-MM-DD format.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            $employeeId = (int) $lookupCache->getEmployeeIdByOldEmployeeCode($oldEmployeeCode);
            if ($employeeId <= 0) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'Employee not found for old employee code.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            if (!$this->hasInitialSalary($employeeId, $module, $username)) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'Employee does not have an INITIAL salary record.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            $activeSalary = $this->getActiveSalaryByEmployeeId($employeeId, $module, $username);
            if (!$activeSalary) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'Employee does not have an active salary record.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            if (bccomp((string) $activeSalary['gross'], $currentGross, 2) !== 0) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'Excel current gross does not match the active database gross.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            $newGross = bcadd((string) $activeSalary['gross'], $increment, 2);
            if ($this->checkDuplicateSalaryRecord($employeeId, $newGross, $increment, $newEffectiveFrom, null, 'INCREMENT', $module, $username)) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, 'An identical INCREMENT salary record already exists.', $oldEmployeeCode, $newEmployeeCode);
                continue;
            }

            try {
                $this->addSalaryRecord(
                    $employeeId,
                    $newGross,
                    $increment,
                    $newEffectiveFrom,
                    'INCREMENT',
                    null,
                    $username,
                    $module,
                    $username
                );
            } catch (Exception $exception) {
                $this->addIncrementImportError($duplicateRowsInDb, $rowNumber, $exception->getMessage(), $oldEmployeeCode, $newEmployeeCode);
            }
        }

        return $this->excelHelper->generateErrorReport($duplicateRowsInExcel, $duplicateRowsInDb);
    }

    private function addIncrementImportError(&$errors, $rowNumber, $message, $oldEmployeeCode, $newEmployeeCode): void
    {
        $errors[] = [
            'row_number' => $rowNumber,
            'Error' => $message,
            'data' => [
                'old_employee_code' => $oldEmployeeCode,
                'new_employee_code' => $newEmployeeCode,
            ],
        ];
    }

    public function addSalaryForExcelImport($employeeId, $effectiveFrom, $gross, $module, $username)
    {
        // effective_to is never accepted as input; it is only set when this revision is superseded.
        $effectiveTo = null;
        $status = 1;
        $revisionType = 'INITIAL';
        $increment = null;

        $query = "INSERT INTO tbl_salary
            (employee_id, effective_from, gross, effective_to, status, revision_type, increment, createdBy, updatedBy)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [
            $employeeId,
            $effectiveFrom,
            $gross,
            $effectiveTo,
            $status,
            $revisionType,
            $increment,
            $username,
            $username
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->insert($query, $params, 'Initial salary record imported ');
    }
}
