<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/LookupCache.php';
// tbl_contact table structure
// id	int	NO	PRI		auto_increment
// f_name	varchar(50)	NO			
// l_name	varchar(50)	NO			
// dob	date	YES			
// email	varchar(100)	NO			
// personal_email	varchar(55)	YES			
// mobile	varchar(15)	NO			
// add1	varchar(100)	NO			
// add2	varchar(100)	NO			
// city	int	NO			
// state	int	NO			
// pin	int	NO			
// country	int	NO			
// contacttype_id	int	NO	MUL		
// join_date	date	YES			
// exit_date	date	YES			
// emp_status	int	YES	MUL		
// entity_id	int	YES			
// department	int	YES	MUL		
// designation	int	YES	MUL		
// image	varchar(100)	YES			
// createdBy	int	NO			
// created_datetime	datetime	NO		CURRENT_TIMESTAMP	DEFAULT_GENERATED
// last_updated	int	YES			
// last_updatedDatetime	datetime	YES			

// tbl_users table structure
// id	int	NO	PRI		auto_increment
// user_name	varchar(255)	NO			
// email	varchar(255)	NO			
// password	varchar(255)	NO			
// user_status	int	NO	MUL		
// contact_id	int	YES	MUL		
// code	mediumint	NO			
// status	text	NO			
// entity_id	int	YES	MUL	1	
// createdBy	int	YES			
// createdDateTime	datetime	YES		CURRENT_TIMESTAMP	DEFAULT_GENERATED
// Last_UpdatedBy	int	YES			
// Last_UpdatedDateTime	datetime	YES			
// manager	varchar(70)	YES			
// manager_email	varchar(70)	YES			

// tbl_user_modules table structure
// id	int	NO	PRI		auto_increment
// user_id	int	NO	MUL		
// email	varchar(50)	NO			
// module_id	int	NO			
// user_role_id	int	NO	MUL		
// enabled	tinyint(1)	YES		1	
// created_by	int	NO			
// created_datetime	datetime	YES		CURRENT_TIMESTAMP	DEFAULT_GENERATED
// last_updated	int	YES			
// last_updated_datetime	datetime	YES			

// tbl_employee table structure
// CREATE TABLE tbl_employee (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     emp_code VARCHAR(20) NOT NULL UNIQUE,
//     entity_id INT NOT NULL,
//     contact_id INT NOT NULL,
//     user_id INT NOT NULL,
//     emp_status INT NOT NULL,
//     uan VARCHAR(20) NOT NULL,
//     aadhar VARCHAR(20) NOT NULL,
//     pan_no VARCHAR(20) NOT NULL,
//     esi_no VARCHAR(20) NOT NULL,
//     bank_name VARCHAR(100) NOT NULL,
//     bank_account_no VARCHAR(25) NOT NULL,
//     ifsc_code VARCHAR(20) NOT NULL,
//     m365 BOOLEAN NOT NULL,
//     old_emp_code VARCHAR(20) NOT NULL,
//     createdBy INT NOT NULL,
//     created_at DATETIME NOT NULL,
//     updatedBy INT NOT NULL,
//     updated_at DATETIME NOT NULL,
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

class Employee
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
        $this->excelHelper = new ExcelHelper($_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/employee.ini');
    }

    // function to get all employees with pagination
    public function getPaginatedEmployees($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = 'SELECT * FROM tbl_employee LIMIT ? OFFSET ?';
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$limit, $offset]);
    }

    // function to get all employees with pagination
    public function getEmployeesCount($module, $username)
    {
        $query = 'SELECT COUNT(*) FROM tbl_employee';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    // function to get employee by id
    public function getEmployeeById($id, $module, $username)
    {
        $query = 'SELECT * FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$id]);
    }

    // function to get employee by email
    public function getEmployeeByEmail($email, $module, $username)
    {
        $query = 'SELECT * FROM tbl_employee WHERE email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$email]);
    }

    // function to add an employee
    // adding an employee contains multiple steps
    // 1. add the employee to the tbl_contact table
    // 2. add the employee to the tbl_users table 
    // 3. add the employee to the tbl_user_modules table as BASE EMPLOYEE role
    // 4. add the employee to the tbl_employee table
    public function addEmployeeRecordForExcelImport(
        $f_name,
        $l_name,
        $birth_date,
        $email,
        $personal_email,
        $mobile,
        $add1,
        $add2,
        $cityId,
        $stateId,
        $countryId,
        $pin,
        $contactTypeId,
        $join_date,
        $exit_date,
        $statusId,
        $entityId,
        $departmentId,
        $designationId,
        $image,
        $uan,
        $aadhar,
        $pan_no,
        $esi_no,
        $bank_name,
        $bank_account_no,
        $ifsc_code,
        $m365,
        $old_emp_code,
        $userId,
        $module,
        $username
    ) {
        $transactionStarted = false;
        try {


            // validate the data before adding to the tbl_contact table
            $f_name = trim($f_name);
            $l_name = trim($l_name);
            // if m365 is true, then the email should be the same as the personal email
            // if m365 is true, validate if the email exists in thel tbl_m365_users table
            if ($m365) {
                $query = 'SELECT * FROM tbl_m365_users WHERE user_principal_name = ?';
                $this->logger->logQuery($query, [$email], 'classes', $module, $username);
                $result = $this->conn->runSingle($query, [$email]);
                if ($result) {
                    $email = $result['user_principal_name'];
                    $personal_email = $email;
                    $f_name = $result['first_name'];
                    $l_name = $result['last_name'];
                }
            }

            // $transactionStarted = true;
            // $this->conn->beginTrans();
            
            $contactId = $this->addContact($f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $cityId, $stateId, $pin, $countryId, $contactTypeId, $join_date, $exit_date, $statusId, $entityId, $departmentId, $designationId, $image, $userId, $module, $username);
            if (!$contactId) {
                throw new Exception('Contact not added');
            }
            $userId = $this->addUser($email, $statusId, $contactId, $entityId, $userId, $module, $username);
            if (!$userId) {
                throw new Exception('User not added');
            }
            $userModuleId = $this->addUserModuleAsBaseEmployee($userId, $email, $userId, $module, $username);
            if (!$userModuleId) {
                throw new Exception('User module not added as Base Employee');
            }
            $employeeId = $this->addEmployee($entityId, $contactId, $userId, $statusId, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $old_emp_code, $userId, $module, $username);
            if (!$employeeId) {
                throw new Exception('Employee not added');
            }

            // $this->conn->commitTrans();
            return true;
        } catch (Exception $e) {
            // if ($transactionStarted) {
            //     // $this->conn->rollbackTrans();
            // }
            $this->logger->log('Failed to add employee record: ' . $e->getMessage(), 'classes', $module);
            throw new Exception('Failed to add employee record: ' . $e->getMessage());
        }
    }

    /**
     * Add employee record as in Excel import: expects IDs, not names, for all foreign keys.
     * Returns true on success, array with 'error' key on failure.
     */
    public function addEmployeeRecordForManualImport(
        $f_name,
        $l_name,
        $birth_date,
        $email,
        $personal_email,
        $mobile,
        $add1,
        $add2,
        $cityId,
        $stateId,
        $countryId,
        $pin,
        $contactTypeId,
        $join_date,
        $exit_date,
        $statusId,
        $entityId,
        $departmentId,
        $designationId,
        $image,
        $uan,
        $aadhar,
        $pan_no,
        $esi_no,
        $bank_name,
        $bank_account_no,
        $ifsc_code,
        $m365,
        $old_emp_code,
        $userId,
        $module,
        $username
    ) {
        $transactionStarted = false;
        try {
            $f_name = trim($f_name);
            $l_name = trim($l_name);

            $this->conn->beginTrans();
            $transactionStarted = true;

            // Pass everything as IDs, just like Excel import
            $contactId = $this->addContact(
                $f_name,
                $l_name,
                $birth_date,
                $email,
                $personal_email,
                $mobile,
                $add1,
                $add2,
                $cityId,
                $stateId,
                $pin,
                $countryId,
                $contactTypeId,
                $join_date,
                $exit_date,
                $statusId,
                $entityId,
                $departmentId,
                $designationId,
                $image,
                $userId,
                $module,
                $username
            );
            if (!$contactId) {
                throw new Exception('Contact not added');
            }

            $userId = $this->addUser($email, $statusId, $contactId, $entityId, $userId, $module, $username);
            if (!$userId) {
                throw new Exception('User not added');
            }

            $userModuleId = $this->addUserModuleAsBaseEmployee($userId, $email, $userId, $module, $username);
            if (!$userModuleId) {
                throw new Exception('User module not added as Base Employee');
            }

            $employeeId = $this->addEmployee(
                $entityId,
                $contactId,
                $userId,
                $statusId,
                $uan,
                $aadhar,
                $pan_no,
                $esi_no,
                $bank_name,
                $bank_account_no,
                $ifsc_code,
                $m365,
                $old_emp_code,
                $userId,
                $module,
                $username
            );
            if (!$employeeId) {
                throw new Exception('Employee not added');
            }

            $this->conn->commitTrans();
            return true;
        } catch (Exception $e) {
            if ($transactionStarted) {
                $this->conn->rollbackTrans();
            }
            $this->logger->log('Failed to add employee record: ' . $e->getMessage(), 'classes', $module);
            return ['error' => 'Failed to add employee record: ' . $e->getMessage()];
        }
    }

    public function importDataFromExcel($batchId, $module, $username)
    {
        $rows = $this->excelHelper->selectTemporaryTableRows($batchId);
        $tableName = $this->excelHelper->getMainTableName();
        if (empty($rows)) {
            throw new Exception('No data found in temporary table for batch id: ' . $batchId);
        }

        // first get the rows from the temporary table 
        // now loop through tht rows in temporary table and remove those rows that are empty and duplicate in the tmp table itself. store them in the duplicateRowsInExcelFile array
        // store the valid rows in the cleanedRows array
        // now loop through the cleanedRows array and check against the main table to check for duplicates
        // store the duplicate rows in the duplicateRowsInDb array

        // duplicate rows in the excel file are not allowed
        // store duplicate rows for error reporting with the row number in the excel file
        $rowNumber = 1;
        // cleanedRows contain the rows in the excel file that are valid and unique
        $cleanedRows = [];
        $duplicateRowsInExcelFile = [];
        foreach ($rows as $row) {
            if ($row['m365'] === 'Y' || $row['m365'] === 'y' || $row['m365'] === 'Yes' || $row['m365'] === 'yes') {
                $key = strtolower($row['email']) . '_' . strtolower($row['entity_code']);
            } else {
                $key = strtolower($row['old_emp_code']) . '_' . strtolower($row['entity_code']);
            }
            if ($row['f_name'] === '' || $row['l_name'] === '') {
                $duplicateRowsInExcelFile[] = ['Error' => "Row $rowNumber has empty fields. First name and last name are required."];
            } else if (isset($cleanedRows[$key])) {
                $duplicateRowsInExcelFile[] = ['row_number' => $rowNumber, 'data' => ['f_name' => $row['f_name'], 'l_name' => $row['l_name'], 'entity_code' => $row['entity_code']]];
            } else {
                $cleanedRows[$key] = $row;
            }
            $rowNumber++;
        }

        $duplicateRowsInDb = [];

        // get the existing rows from the main table to check for duplicates
        if ($row['m365'] === 'Y' || $row['m365'] === 'y' || $row['m365'] === 'Yes' || $row['m365'] === 'yes') {
            $existingRows = $this->conn->runQuery("SELECT user.email AS email, ent.entity_code AS entity_code FROM $tableName emp 
                                JOIN tbl_entity ent ON emp.entity_id = ent.id 
                                JOIN tbl_users user on user.id = emp.user_id;");
            $existingRowsMap = [];
            foreach ($existingRows as $existingRow) {
                $key = strtolower($existingRow['email']) . '_' . strtolower($existingRow['entity_id']);
                $existingRowsMap[$key] = true;
            }
        } else {
            $existingRows = $this->conn->runQuery("SELECT old_emp_code, entity_id FROM $tableName");
            $existingRowsMap = [];
            foreach ($existingRows as $existingRow) {
                $key = strtolower($existingRow['old_emp_code']) . '_' . strtolower($existingRow['entity_id']);
                $existingRowsMap[$key] = true;
            }
        }

        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        // $transactionStarted = false;
        // $this->conn->beginTrans();

        foreach ($cleanedRows as $row) {
            // $row means the column in the db (so map with the column names in the db)


            if (strtolower(trim($row['emp_status'])) !== 'active' && strtolower(trim($row['emp_status'])) !== 'in-active' && strtolower(trim($row['emp_status'])) !== 'suspended' && strtolower(trim($row['emp_status'])) !== 'blocked') {
                throw new Exception('Invalid value for emp_status: ' . $row['emp_status']);
            }
            if (strtolower(trim($row['emp_type'])) !== 'regular' && strtolower(trim($row['emp_type'])) !== 'non regular') {
                throw new Exception('Invalid value for emp_type: ' . $row['emp_type']);
            }

            $contactType = null;
            if (strtolower(trim($row['emp_type'])) === 'regular') {
                $contactType = 'Employee';
            } else {
                $contactType = 'Consultant';
            }

            // only allow Y, y, Yes, yes, No, no, YES, YES, NO, NO
            if (strtolower(trim($row['m365'])) !== 'y' && strtolower(trim($row['m365'])) !== 'yes' && strtolower(trim($row['m365'])) !== 'n' && strtolower(trim($row['m365'])) !== 'no') {
                throw new Exception('Invalid value for m365: ' . $row['m365']);
            }

            if (strtolower(trim($row['m365'])) === 'y' || strtolower(trim($row['m365'])) === 'yes') {
                $m365 = true;
            } else {
                $m365 = false;
            }

            if ($m365) {
                $key = strtolower($row['email']) . '_' . strtolower($row['entity_code']);
            } else {
                $key = strtolower($row['old_emp_code']) . '_' . strtolower($row['entity_code']);
            }
            if (isset($existingRowsMap[$key])) {
                $duplicateRowsInDb[] = ['data' => ['email' => $row['email'], 'old_emp_code' => $row['old_emp_code'], 'entity_id' => $row['entity_id']]];
                continue;
            }


            // validate the data
            $cityId = intval($lookupCache->getLocationCityId(strtolower(trim($row['city']))));
            if (!$cityId) {
                throw new Exception('City not found: ' . $row['city']);
            }
            $stateId = intval($lookupCache->getLocationStateId(strtolower(trim($row['city']))));
            if (!$stateId) {
                throw new Exception('State not found: ' . $row['state']);
            }
            $countryId = intval($lookupCache->getLocationCountryId(strtolower(trim($row['city']))));
            if (!$countryId) {
                throw new Exception('Country not found: ' . $row['country']);
            }
            $contactTypeId = intval($lookupCache->getContactTypeId(strtolower(trim($contactType))));
            if (!$contactTypeId) {
                throw new Exception('Contact type not found: ' . $contactType);
            }
            $statusId = intval($lookupCache->getStatusId(strtolower(trim($row['emp_status']))));
            if (!$statusId) {
                throw new Exception('Status not found: ' . $row['emp_status']);
            }
            $entityId = intval($lookupCache->getEntityId(strtolower(trim($row['entity_code']))));
            if (!$entityId) {
                throw new Exception('Entity not found: ' . $row['entity_code']);
            }
            $departmentId = intval($lookupCache->getDepartmentId(strtolower(trim($row['department']))));
            if (!$departmentId) {
                throw new Exception('Department not found: ' . $row['department']);
            }
            $designationId = intval($lookupCache->getDesignationId(strtolower(trim($row['designation']))));
            if (!$designationId) {
                throw new Exception('Designation not found: ' . $row['designation']);
            }

            $joinDate = $row['doj'] ?? null;
            // $exitDate = $row['doe'] ? DateTime::createFromFormat('d-m-Y', $row['doe']) : null;
            $exitDate = null;
            // example: 30-06-2026
            $dateOfBirth = $row['dob'] ?? null;
            $row['image'] = null;
            $row['mobile'] = $row['mobile'] ?? null;
            $row['pin'] = $row['pin'] ?? 0;
            $row['add1'] = $row['add1'] ?? null;
            $row['add2'] = $row['add2'] ?? null;

            // insert the data using the addEmployeeRecordForExcelImport function
            $this->addEmployeeRecordForExcelImport(
                $row['f_name'],
                $row['l_name'],
                $dateOfBirth,
                $row['email'],
                $row['email'],
                $row['mobile'],
                $row['add1'],
                $row['add2'],
                $cityId,
                $stateId,
                $countryId,
                $row['pin'],
                $contactTypeId,
                $joinDate,
                $exitDate,
                $statusId,
                $entityId,
                $departmentId,
                $designationId,
                $row['image'],
                $row['uan'],
                $row['aadhar'],
                $row['pan_no'],
                $row['esi_no'],
                $row['bank_name'],
                $row['bank_account_no'],
                $row['ifsc_code'],
                $m365,
                $row['old_emp_code'],
                $username,
                $module,
                $username
            );
            // $this->conn->commitTrans();
            return $this->excelHelper->generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb);
        }
    }


    // function to create an employee code based on the employee prefix
    // example: EMP-00001
    public function generateEmployeeCode($entity_id, $module, $username)
    {
        $prefixQuery = 'SELECT emp_prefix FROM tbl_entity WHERE id = ?';
        $this->logger->logQuery($prefixQuery, [$entity_id], 'classes', $module, $username);
        $prefix = $this->conn->runSingle($prefixQuery, [$entity_id]);
        $prefix = $prefix['emp_prefix'] ?? 'EMP';

        $query = 'SELECT MAX(emp_code) FROM tbl_employee WHERE emp_code LIKE ?';
        $this->logger->logQuery($query, [$prefix . '%'], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$prefix . '%']);
        $maxCode = $result['MAX(emp_code)'] ?? 0;
        return $prefix . '-' . str_pad($maxCode + 1, 5, '0', STR_PAD_LEFT);
    }

    public function addContact(
        $f_name,
        $l_name,
        $birth_date,
        $email,
        $personal_email,
        $mobile,
        $add1,
        $add2,
        $city,
        $state,
        $pin,
        $country,
        $contacttype_id,
        $join_date,
        $exit_date,
        $emp_status,
        $entity_id,
        $department,
        $designation,
        $image,
        $userId,
        $module,
        $username
    ) {
        // validate the data before adding to the tbl_contact table
        $existingContactId = null;
        $query = 'SELECT id FROM tbl_contact WHERE email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        if ($result) {
            $existingContactId = $result['id'];
        }
        // if the same contact exists with the same email, then update the contact record instead of adding a new one
        if ($existingContactId) {
            $query = 'UPDATE tbl_contact SET f_name = ?, l_name = ?, dob = ?, email = ?, personal_email = ?, mobile = ?, add1 = ?, add2 = ?, city = ?, state = ?, pin = ?, country = ?, contacttype_id = ?, join_date = ?, exit_date = ?, emp_status = ?, entity_id = ?, department = ?, designation = ?, image = ?, last_updated = ? WHERE id = ?';
            $this->logger->logQuery($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $userId, $existingContactId], 'classes', $module, $username);
            $this->conn->update($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $userId, $existingContactId], 'Contact updated');
            return $existingContactId;
        }
        $query = 'INSERT INTO tbl_contact (f_name, l_name, dob, email, personal_email, mobile, add1, add2, city, state, pin, country, contacttype_id, join_date, exit_date, emp_status, entity_id, department, designation, image, createdBy) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $userId], 'classes', $module, $username);
        $contactId = $this->conn->insert($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $userId], 'Contact added');
        return $contactId;
    }

    public function addUser($email, $user_status, $contact_id, $entity_id, $userId, $module, $username)
    {
        $existingUserId = null;
        $query = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        if ($result) {
            $existingUserId = $result['id'];
        }
        // if the same user exists with the same email, then update the user record instead of adding a new one
        if ($existingUserId) {
            $query = 'UPDATE tbl_users SET user_status = ?, contact_id = ?, entity_id = ?, last_updatedBy = ? WHERE id = ?';
            $this->logger->logQuery($query, [$user_status, $contact_id, $entity_id, $userId, $existingUserId], 'classes', $module, $username);
            $this->conn->update($query, [$user_status, $contact_id, $entity_id, $userId, $existingUserId], 'User updated');
            return $existingUserId;
        }
        $user_name = $email;
        // generate a random password
        $password = bin2hex(random_bytes(8));
        $password = password_hash($password, PASSWORD_BCRYPT);
        $code = 0;
        $status = 'verified';
        $manager = '';
        $manager_email = '';

        $query = 'INSERT INTO tbl_users (user_name, email, password, user_status, contact_id, code, status, entity_id, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$user_name, $email, $password, $user_status, $contact_id, $code, $status, $entity_id, $userId], 'classes', $module, $username);
        $userId = $this->conn->insert($query, [$user_name, $email, $password, $user_status, $contact_id, $code, $status, $entity_id, $userId], 'User added');
        return $userId;
    }

    public function addUserModuleAsBaseEmployee($user_id, $email, $userId, $module, $username)
    {
        $existingUserModuleId = null;
        $query = 'SELECT id FROM tbl_user_modules WHERE user_id = ? AND email = ?';
        $this->logger->logQuery($query, [$user_id, $email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$user_id, $email]);
        if ($result) {
            $existingUserModuleId = $result['id'];
        }
        if ($existingUserModuleId) {
            return $existingUserModuleId;
        }
        $module_id = 2;
        $user_role_id = 5;
        $enabled = 1;

        $query = 'INSERT INTO tbl_user_modules (user_id, email, module_id, user_role_id, enabled, created_by) VALUES (?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$user_id, $email, $module_id, $user_role_id, $enabled, $userId], 'classes', $module, $username);
        $userModuleId = $this->conn->insert($query, [$user_id, $email, $module_id, $user_role_id, $enabled, $userId], 'User module added as Base Employee');
        return $userModuleId;
    }

    public function addEmployee($entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $old_emp_code, $userId, $module, $username)
    {
        $existingEmployeeId = null;
        $query = 'SELECT id FROM tbl_employee WHERE user_id = ?';
        $this->logger->logQuery($query, [$user_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$user_id]);
        if ($result) {
            $existingEmployeeId = $result['id'];
        }
        // if the same employee exists with the same user_id, then update the employee record instead of adding a new one
        if ($existingEmployeeId) {
            $query = 'UPDATE tbl_employee SET entity_id = ?, contact_id = ?, user_id = ?, emp_status = ?, uan = ?, aadhar = ?, pan_no = ?, esi_no = ?, bank_name = ?, bank_account_no = ?, ifsc_code = ?, m365 = ?, updatedBy = ? WHERE id = ?';
            $this->logger->logQuery($query, [$entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $userId, $existingEmployeeId], 'classes', $module, $username);
            $this->conn->update($query, [$entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $userId, $existingEmployeeId], 'Employee updated');
            return $existingEmployeeId;
        }

        $emp_code = $this->generateEmployeeCode($entity_id, $module, $username);
        $query = 'INSERT INTO tbl_employee (emp_code, entity_id, 
            contact_id, user_id, emp_status, 
            uan, aadhar, pan_no, esi_no, bank_name, 
            bank_account_no, ifsc_code, 
            m365, old_emp_code, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$emp_code, $entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $old_emp_code, $userId], 'classes', $module, $username);
        $employeeId = $this->conn->insert($query, [$emp_code, $entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $old_emp_code, $userId], 'Employee added');
        return $employeeId;
    }

    public function getLocationDetails($cityName, $module, $username)
    {
        $query = 'SELECT id as city_id, state as state_id, country as country_id FROM tbl_city WHERE lower(city) = ?';
        $this->logger->logQuery($query, [$cityName], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$cityName]);
        if ($result) {
            return $result;
        }
        return false;
    }

    public function getEntityIdByNameOrCode($entity_name, $module, $username)
    {
        $query = 'SELECT id FROM tbl_entity WHERE lower(entity_name) = ? OR lower(entity_code) = ?';
        $this->logger->logQuery($query, [$entity_name, $entity_name], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$entity_name, $entity_name]);
        if ($result) {
            return $result['id'];
        }
        return false;
    }

    public function getDepartmentIdByName($department_name, $module, $username)
    {
        $query = 'SELECT id FROM tbl_department WHERE lower(name) = ?';
        $this->logger->logQuery($query, [$department_name], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$department_name]);
        if ($result) {
            return $result['id'];
        }
        return false;
    }

    public function getDesignationIdByName($designation_name, $module, $username)
    {
        $query = 'SELECT id FROM tbl_designation WHERE lower(name) = ?';
        $this->logger->logQuery($query, [$designation_name], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$designation_name]);
        if ($result) {
            return $result['id'];
        }
        return false;
    }

    public function getStatusIdByName($status_name, $module, $username)
    {
        $query = 'SELECT id FROM tbl_status WHERE lower(status) = ? AND module = ?';
        $this->logger->logQuery($query, [$status_name, 'GEN'], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$status_name, 'GEN']);
        if ($result) {
            return $result['id'];
        }
        return false;
    }


    public function checkM365UserExists($user_principal_name, $module, $username)
    {
        $query = 'SELECT first_name, last_name FROM tbl_m365_users WHERE user_principal_name = ?';
        $this->logger->logQuery($query, [$user_principal_name], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$user_principal_name]);
        if ($result) {
            return true;
        }
        return false;
    }
}
