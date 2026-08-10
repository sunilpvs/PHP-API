<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/LookupCache.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphAutoMailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/Entity.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use Dotenv\Dotenv;


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
    private $env;
    private $entityOb;

    private static $EMP_QUERY = "SELECT 
                                    emp.id as id,
                                    ent.entity_name as entity_name, dept.name as department, 
                                    desn.name as designation, cont.f_name as first_name, 
                                    cont.l_name as last_name, 
                                    concat(cont.f_name,' ',cont.l_name) as display_name,
                                    cont.dob as dob, cont.personal_email as personal_email, 
                                    cont.mobile, 
                                    CASE WHEN cont.contacttype_id = 2 THEN 'Employee' ELSE 'Contract' END as emp_type,
                                    cont.join_date as joining_date, cont.exit_date as exit_date, 
                                    emp.old_emp_code as old_emp_code,
                                    CASE WHEN emp.m365 = 1 THEN 'Yes' ELSE 'No' END as m365,
                                    cont.email as email, CASE WHEN cont.add1 IS NULL THEN '-' ELSE cont.add1 END as add1,
                                    CASE WHEN cont.add2 IS NULL THEN '-' ELSE cont.add2 END as add2,
                                    country.country as country, state.state as state, city.city as city, 
                                    cont.pin as pin,
                                    emp.aadhar as aadhar, emp.uan as uan, emp.pan_no as pan, emp.esi_no as esi, 
                                    emp.bank_name as bank_name, emp.bank_account_no as bank_account_no, 
                                    emp.ifsc_code as ifsc_code
                                    FROM tbl_employee emp
                                    LEFT JOIN tbl_contact cont ON emp.contact_id = cont.id
                                    JOIN tbl_entity ent on emp.entity_id = ent.id
                                    JOIN tbl_department dept on cont.department = dept.id
                                    JOIN tbl_designation desn on cont.designation = desn.id
                                    JOIN tbl_country country on cont.country = country.id
                                    JOIN tbl_state state on cont.state = state.id
                                    JOIN tbl_city city on cont.city = city.id";

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
        $this->excelHelper = new ExcelHelper($_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/employee.ini');
        $this->entityOb = new Entity();

        $this->env = getenv('APP_ENV') ?: 'local';
        if ($this->env === 'production') {

            $dotenv = Dotenv::createImmutable(__DIR__ . "/../../", ".env.prod");
        } else {
            $dotenv = Dotenv::createImmutable(__DIR__ . "/../../", ".env");
        }
        $dotenv->load();
    }

    private function normalizeEmployeeType($empType)
    {
        if (is_numeric($empType)) {
            return ((int) $empType === 1) ? 'regular' : 'contract';
        }

        $normalizedEmpType = strtolower(trim((string) $empType));
        if ($normalizedEmpType === 'regular') {
            return 'regular';
        }

        if (in_array($normalizedEmpType, ['contract', 'non regular', 'non-regular'], true)) {
            return 'contract';
        }

        throw new Exception('Invalid value for emp_type: ' . $empType);
    }

    private function normalizeM365Flag($m365)
    {
        if (is_bool($m365)) {
            return $m365;
        }

        if (is_int($m365) || is_float($m365)) {
            return ((int) $m365) === 1;
        }

        return in_array(strtolower(trim((string) $m365)), ['y', 'yes', '1', 'true'], true);
    }

    private function normalizeExitDate($employeeType, $exitDate)
    {
        $exitDate = trim((string) $exitDate);
        if ($employeeType === 'regular') {
            return null;
        }

        if ($exitDate === '') {
            throw new Exception('Exit date is mandatory for contract employees');
        }

        return $exitDate;
    }

    private function ensureM365EmailIsAvailable($email, $module, $username)
    {
        $m365User = $this->checkM365UserExists($email, $module, $username);
        if ($m365User) {
            throw new Exception('Email already exists in M365.');
        }
    }

    // function to get all employees with pagination
    public function getPaginatedEmployees($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = self::$EMP_QUERY . " ORDER BY emp.id ASC LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query, []);
    }

    // function to get all employees with pagination
    public function getEmployeesCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM tbl_employee';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return $result[0]['total'] ?? 0;
    }

    // function to get employee by id
    public function getEmployeeById($id, $module, $username)
    {
        $query = self::$EMP_QUERY . " WHERE emp.id = ?";
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$id]);
    }

    // function to get employee by email
    public function getEmployeeByEmail($email, $module, $username)
    {
        $query = self::$EMP_QUERY . " WHERE emp.email = ?";
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
        $officeLocationId,
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
        try {
            // validate the data before adding to the tbl_contact table
            $f_name = trim($f_name);
            $l_name = trim($l_name);
            var_dump($contactTypeId);

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
            $employeeId = $this->addEmployee($entityId, $contactId, $userId, $statusId, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $old_emp_code, $userId, $module, $username);
            if (!$employeeId) {
                throw new Exception('Employee not added');
            }

            return true;
        } catch (Exception $e) {
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
        $join_date,
        $exit_date,
        $empType,
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
        $officeLocationId,
        $old_emp_code,
        $userId,
        $module,
        $username
    ) {
        try {
            $f_name = trim($f_name);
            $l_name = trim($l_name);
            $empType = $this->normalizeEmployeeType($empType);
            $m365 = $this->normalizeM365Flag($m365);
            $exit_date = $this->normalizeExitDate($empType, $exit_date);

            if ($m365) {
                if (strtolower(trim((string) $email)) === strtolower(trim((string) $personal_email))) {
                    throw new Exception('Personal email must be different from email when m365 is enabled');
                }

                $this->ensureM365EmailIsAvailable($email, $module, $username);
            } else {
                $personal_email = $email;
            }
            if ($empType === 'regular') {
                $contactTypeId = 2; // Employee
            } else {
                $contactTypeId = 3; // Consultant
            }


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
                $officeLocationId,
                $old_emp_code,
                $userId,
                $module,
                $username
            );
            if (!$employeeId) {
                throw new Exception('Employee not added');
            }

            $salutationName = $this->entityOb->getSalutationNameByEntityId($entityId, $module, $username);
            $empCode = $this->getEmployeeCodeById($employeeId, $module, $username);

            // send a mail to IT admin with cc to the hr to create the M365 account for the employee if m365 is Y, y, Yes, yes
            $mailer = new AutoMail();
            $itAdminEmails = $this->getItAdminEmails('hr', 'system');
            // $hrEmail = $this->getHrEmailByEntityId($entityId, $module, $username);
            if ($m365) {
                $name = $salutationName ? $salutationName : 'Shrichandra Group Team';
                $keyValueData = [
                    "Message" => "A new employee record has been added for $f_name $l_name. Please create an M365 account for this employee. Refer Admin Portal for more details.",
                    "Employee Name" => $f_name . ' ' . $l_name,
                    "Employee Email" => $email,
                    "Employee Code" => $empCode,
                    // get it admin portal url from the env file
                    "IT Admin Portal URL" => $_ENV['ADMIN_PORTAL_URL'] ?? 'Not Set in Env'
                ];
                $emailSent = $mailer->sendInfoEmail(
                    subject: "New Employee Record Added - M365 Account Creation Required",
                    greetings: "Dear IT Admin,",
                    name: $salutationName ? $salutationName : 'Shrichandra Group Team',
                    keyValueArray: $keyValueData,
                    to: $itAdminEmails,
                    cc: [], // add hr mail
                    bcc: $itAdminEmails,
                );

                if (!$emailSent) {
                    throw new Exception('Failed to send email to IT Admin for M365 account creation');
                }
            }

            // $this->conn->commitTrans();
            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to add employee record: ' . $e->getMessage(), 'classes', $module);
            return ['exception' => 'Failed to add employee record:', 'error' => $e->getMessage()];
        }
    }

    // TODO: add office location column
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
            if (strtolower(trim($row['m365'])) === 'y' || strtolower(trim($row['m365'])) === 'yes') {
                $result = $this->checkM365UserExists($row['email'], $module, $username);
                if ($result !== null) {
                    $duplicateRowsInExcelFile[] = [
                        'row_number' => $rowNumber,
                        'Error' => "Row has duplicate email. Email already exists in M365.",
                        'data' => [
                            'email' => $row['email'],
                        ]
                    ];
                    $rowNumber++;
                    continue;
                }
                $key = strtolower($row['email']) . '_' . strtolower($row['entity_code']);
            } else {
                $key = strtolower($row['old_emp_code']) . '_' . strtolower($row['entity_code']);
            }

            if (trim($row['f_name']) === '' || trim($row['l_name']) === '') {
                $duplicateRowsInExcelFile[] = [
                    'row_number' => $rowNumber,
                    'Error' => "Row has empty fields. First name and last name are required.",
                    'data' => [
                        'f_name' => $row['f_name'],
                        'l_name' => $row['l_name'],
                    ]
                ];
            } else if (isset($cleanedRows[$key])) {
                $duplicateRowsInExcelFile[] = [
                    'row_number' => $rowNumber,
                    'Error' => "Row is a duplicate.",
                    'data' => [
                        'f_name' => $row['f_name'],
                        'l_name' => $row['l_name'],
                        'email' => $row['email']
                    ]
                ];
            } else {
                $cleanedRows[$key] = $row;
            }
            $rowNumber++;
        }

        // check cleaned rows for email against tbl_m365_users table if m365 is Y, y, Yes, yes

        $duplicateRowsInDb = [];

        // Existing M365 employees
        $m365ExistingRows = $this->conn->runQuery("SELECT user.email AS email, ent.entity_code AS entity_code FROM $tableName emp 
                                JOIN tbl_entity ent ON emp.entity_id = ent.id 
                                JOIN tbl_users user on user.id = emp.user_id");
        $m365ExistingRowsMap = [];

        foreach ($m365ExistingRows as $m365ExistingRow) {
            $key = strtolower($m365ExistingRow['email']) . '_' . strtolower($m365ExistingRow['entity_code']);
            $m365ExistingRowsMap[$key] = true;
        }

        // Existing non-M365 employees
        $nonM365ExistingRows = $this->conn->runQuery("SELECT emp.old_emp_code AS old_emp_code , ent.entity_code AS entity_code 
                                FROM $tableName emp 
								JOIN tbl_entity ent ON ent.id = emp.entity_id");
        $nonM365ExistingRowsMap = [];
        foreach ($nonM365ExistingRows as $nonM365ExistingRow) {
            $key = strtolower($nonM365ExistingRow['old_emp_code']) . '_' . strtolower($nonM365ExistingRow['entity_code']);
            $nonM365ExistingRowsMap[$key] = true;
        }



        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        foreach ($cleanedRows as $row) {
            // $row means the column in the db (so map with the column names in the db)


            if (strtolower(trim($row['emp_status'])) !== 'active' && strtolower(trim($row['emp_status'])) !== 'in-active' && strtolower(trim($row['emp_status'])) !== 'suspended' && strtolower(trim($row['emp_status'])) !== 'blocked') {
             
            throw new Exception('Invalid value for emp_status: ' . $row['emp_status']);
            var_dump($row['emp_status']);    
            throw new Exception('Invalid value for emp_status: ' . $row['emp_status']);
            }
            $empType = strtolower(str_replace('-', ' ', trim($row['emp_type'])));
            if ($empType !== 'regular' && $empType !== 'non regular') {
                throw new Exception('Invalid value for emp_type: ' . $row['emp_type']);
            }

            $contactType = null;
            if ($empType === 'regular') {
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
                if (isset($m365ExistingRowsMap[$key])) {
                    $duplicateRowsInDb[] = ['data' => [
                        'email' => $row['email'],
                        'entity_code' => $row['entity_code']
                    ]];
                    continue;
                }
            } else {
                $key = strtolower($row['old_emp_code']) . '_' . strtolower($row['entity_code']);
                if (isset($nonM365ExistingRowsMap[$key])) {
                    $duplicateRowsInDb[] = ['data' => [
                        'old_emp_code' => $row['old_emp_code'],
                        'entity_code' => $row['entity_code']
                    ]];
                    continue;
                }
            }

            // validate the data
            $cityId = intval($row['city']);
            $stateId = intval($row['state']);
            $countryId = intval($row['country']);
            
            $cityId = intval($row['city']);
            $stateId = intval($row['state']);
            $countryId = intval($row['country']);
            
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
            $departmentId = intval($row['department']);
            $designationId = intval($row['designation']);
            $departmentId = intval($row['department']);
            $designationId = intval($row['designation']);

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

            // TODO: get office location id
            $officeLocationId = 1;

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
                $officeLocationId,
                $officeLocationId,
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
        }
        return $this->excelHelper->generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb);
    }

    // function to create an employee code based on the employee prefix
    // example: EMP-00001
    public function generateEmployeeCode($entity_id, $module, $username)
    {
        $prefixQuery = 'SELECT emp_prefix FROM tbl_entity WHERE id = ?';
        $this->logger->logQuery($prefixQuery, [$entity_id], 'classes', $module, $username);
        $prefix = $this->conn->runSingle($prefixQuery, [$entity_id]);
        $prefix = $prefix['emp_prefix'] ?? 'EMP';

        $query = 'SELECT COUNT(*) FROM tbl_employee WHERE emp_code LIKE ?';
        $this->logger->logQuery($query, [$prefix . '%'], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$prefix . '%']);
        $maxCode = $result['COUNT(*)'] ?? 0;
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
        $query = 'SELECT id FROM tbl_contact WHERE email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        if ($result) {
            return $result['id'];
        }
        // if the contact does not exist, add it
        $query = 'INSERT INTO tbl_contact (f_name, l_name, dob, email, personal_email, mobile, add1, add2, city, state, pin, country, contacttype_id, join_date, exit_date, emp_status, entity_id, department, designation, image, createdBy) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $userId], 'classes', $module, $username);
        $contactId = $this->conn->insert($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $userId], 'Contact added');
        return $contactId;
    }

    public function addUser($email, $user_status, $contact_id, $entity_id, $userId, $module, $username)
    {
        $query = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        if ($result) {
            return $result['id'];
        }
        // if the user does not exist, add it
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

    public function addEmployee($entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $old_emp_code, $userId, $module, $username)
    {

        $m365 = ($m365 === true || $m365 === 1 || in_array(strtolower(trim((string)$m365)), ['y', 'yes', '1', 'true'], true)) ? 1 : 0;

        $query = 'SELECT id FROM tbl_employee WHERE user_id = ?';
        $this->logger->logQuery($query, [$user_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$user_id]);
        if ($result) {
            return $result['id'];
        }

        $officeLocationId = $officeLocationId ?: 1; // default to 1 if not provided

        // if the employee does not exist, add it
        $emp_code = $this->generateEmployeeCode($entity_id, $module, $username);
        $query = 'INSERT INTO tbl_employee (emp_code, entity_id,
            contact_id, user_id, emp_status, 
            uan, aadhar, pan_no, esi_no, bank_name, 
            bank_account_no, ifsc_code, 
            m365, office_location_id, old_emp_code, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            m365, office_location_id, old_emp_code, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$emp_code, $entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $old_emp_code, $userId], 'classes', $module, $username);
        $employeeId = $this->conn->insert($query, [$emp_code, $entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $old_emp_code, $userId], 'Employee added');
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
            return $result;
        }
        return null;
    }

    public function getEmployeeCodeById($employeeId, $module, $username)
    {
        $query = 'SELECT emp_code FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$employeeId]);
        if ($result) {
            return $result['emp_code'];
        }
        return null;
    }

    public function getItAdminEmails($module, $username)
    {
        $query = 'SELECT email FROM tbl_user_modules WHERE user_role_id = 2 AND module_id = 1';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        if ($result) {
            return array_column($result, 'email');
        }
        return [];
    }

    // duplilcate check helper functions
    public function checkDuplicateEmployeeByEmail($email, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp JOIN tbl_contact cont ON emp.contact_id = cont.id WHERE cont.email = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$email, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$email, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }

    public function checkDuplicateEmployeeByPersonalEmail($personal_email, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp JOIN tbl_contact cont ON emp.contact_id = cont.id WHERE cont.personal_email = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$personal_email, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$personal_email, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }

    public function checkDuplicateEmployeeByAadhar($aadhar, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp WHERE emp.aadhar = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$aadhar, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$aadhar, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }

    public function checkDuplicateEmployeeByPan($pan_no, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp WHERE emp.pan_no = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$pan_no, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$pan_no, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }

    public function checkDuplicateEmployeeByMobile($mobile, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp JOIN tbl_contact cont ON emp.contact_id = cont.id WHERE cont.mobile = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$mobile, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$mobile, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }

    public function checkDuplicateEmployeeByUAN($uan, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp WHERE emp.uan = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$uan, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$uan, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }

    public function checkDuplicateEmployeeByBankAccount($bank_account_no, $entity_id)
    {
        $query = 'SELECT 1 FROM tbl_employee emp WHERE emp.bank_account_no = ? AND emp.entity_id = ?';
        $this->logger->logQuery($query, [$bank_account_no, $entity_id], 'classes', 'system', 'system');
        $result = $this->conn->runSingle($query, [$bank_account_no, $entity_id]);
        if ($result) {
            return true;
        }
        return false;
    }
}
