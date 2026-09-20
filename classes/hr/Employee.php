<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/LookupCache.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphAutoMailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/Entity.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/M365Admins.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use Dotenv\Dotenv;

use function PHPSTORM_META\type;

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
//     required_payslip BOOLEAN DEFAULT FALSE NOT NULL,
//     createdBy INT NOT NULL,
//     created_at DATETIME NOT NULL,
//     updatedBy INT NOT NULL,
//     updated_at DATETIME NOT NULL,
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

class Employee
{
    private $conn;
    private $logger;
    /** @var ExcelHelper */
    private $excelHelper;
    private $env;
    private $entityOb;
    private $m365AdminsOb;

    private static $EMP_QUERY = "SELECT 
                                    emp.id as id,
                                    ent.id as entity_id,    
                                    ent.entity_name as entity_name, 
                                    dept.id as department_id,
                                    dept.name as department, 
                                    desn.id as designation_id,
                                    desn.name as designation, cont.f_name as first_name, 
                                    cont.l_name as last_name, 
                                    concat(cont.f_name,' ',cont.l_name) as display_name,
                                    cont.dob as dob, cont.personal_email as personal_email, 
                                    cont.mobile, 
                                    CASE WHEN cont.contacttype_id = 2 THEN 'Employee' ELSE 'Contract' END as emp_type,
                                    cont.join_date as joining_date, cont.exit_date as exit_date, 
                                    emp.old_emp_code as old_emp_code,
                                    emp.emp_code as emp_code, CASE WHEN emp.emp_status = 1 THEN 'Active' WHEN emp.emp_status = 2 THEN 'In-Active' 
                                    WHEN emp.emp_status = 3 THEN 'Suspended' WHEN emp.emp_status = 4 THEN 'Blocked' ELSE 'Unknown' END as emp_status,
                                    CASE WHEN emp.m365 = 1 THEN 'Yes' ELSE 'No' END as m365,
                                    cont.email as email, CASE WHEN cont.add1 IS NULL THEN '-' ELSE cont.add1 END as add1,
                                    CASE WHEN cont.add2 IS NULL THEN '-' ELSE cont.add2 END as add2,
                                    country.id as country_id,
                                    country.country as country, 
                                    state.id as state_id,
                                    state.state as state, 
                                    city.id as city_id,
                                    city.city as city, 
                                    office_location.id as office_location_id,
                                    office_location.name as office_location,
                                    cont.pin as pin,
                                    emp.aadhar as aadhar, emp.uan as uan, emp.pan_no as pan, emp.esi_no as esi, 
                                    emp.bank_name as bank_name, emp.bank_account_no as bank_account_no, 
                                    emp.ifsc_code as ifsc_code,
                                    emp.required_payslip as required_payslip
                                    FROM tbl_employee emp
                                    LEFT JOIN tbl_contact cont ON emp.contact_id = cont.id
                                    JOIN tbl_entity ent on emp.entity_id = ent.id
                                    JOIN tbl_department dept on cont.department = dept.id
                                    JOIN tbl_designation desn on cont.designation = desn.id
                                    JOIN tbl_country country on cont.country = country.id
                                    JOIN tbl_state state on cont.state = state.id
                                    JOIN tbl_city city on cont.city = city.id
                                    JOIN mas_office_location office_location on emp.office_location_id = office_location.id";

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
        $this->excelHelper = new ExcelHelper($_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/employee.ini');
        $this->entityOb = new Entity();
        $this->m365AdminsOb = new M365Admins();

        $this->env = getenv('APP_ENV') ?: 'local';
        if ($this->env === 'production') {

            $dotenv = Dotenv::createImmutable(__DIR__ . "/../../", ".env.prod");
        } else {
            $dotenv = Dotenv::createImmutable(__DIR__ . "/../../", ".env");
        }
        $dotenv->load();
    }

    public function getExportQuery(): string
    {
        $query = "SELECT 
                    ent.entity_name AS 'Entity',
                    dept.name AS 'Department',
                    desn.name AS 'Designation',

                    cont.f_name AS 'First Name',
                    cont.l_name AS 'Last Name',
                    CONCAT(cont.f_name, ' ', cont.l_name) AS 'Display Name',

                    cont.dob AS 'Date of Birth',
                    cont.personal_email AS 'Personal Email',
                    cont.mobile AS 'Mobile',

                    CASE 
                        WHEN cont.contacttype_id = 2 THEN 'Employee'
                        ELSE 'Contract'
                    END AS 'Employee Type',

                    cont.join_date AS 'Joining Date',
                    cont.exit_date AS 'Exit Date',

                    emp.old_emp_code AS 'Old Employee Code',
                    emp.emp_code AS 'Employee Code',

                    CASE 
                        WHEN emp.emp_status = 1 THEN 'Active'
                        WHEN emp.emp_status = 2 THEN 'In-Active'
                        WHEN emp.emp_status = 3 THEN 'Suspended'
                        WHEN emp.emp_status = 4 THEN 'Blocked'
                        ELSE 'Unknown'
                    END AS 'Employee Status',

                    CASE 
                        WHEN emp.m365 = 1 THEN 'Yes'
                        ELSE 'No'
                    END AS 'M365',

                    cont.email AS 'Email',

                    CASE 
                        WHEN cont.add1 IS NULL THEN '-'
                        ELSE cont.add1
                    END AS 'Address 1',

                    CASE 
                        WHEN cont.add2 IS NULL THEN '-'
                        ELSE cont.add2
                    END AS 'Address 2',

                    country.country AS 'Country',
                    state.state AS 'State',
                    city.city AS 'City',

                    office_location.name AS 'Office Location',

                    cont.pin AS 'PIN',

                    emp.aadhar AS 'Aadhar',
                    emp.uan AS 'UAN',
                    emp.pan_no AS 'PAN',
                    emp.esi_no AS 'ESI Number',

                    emp.bank_name AS 'Bank Name',
                    emp.bank_account_no AS 'Bank Account Number',
                    emp.ifsc_code AS 'IFSC Code',

                    CASE 
                        WHEN emp.required_payslip = 1 THEN 'Yes'
                        ELSE 'No'
                    END AS 'Required Payslip'

                FROM tbl_employee emp

                LEFT JOIN tbl_contact cont 
                    ON emp.contact_id = cont.id

                JOIN tbl_entity ent 
                    ON emp.entity_id = ent.id

                JOIN tbl_department dept 
                    ON cont.department = dept.id

                JOIN tbl_designation desn 
                    ON cont.designation = desn.id

                JOIN tbl_country country 
                    ON cont.country = country.id

                JOIN tbl_state state 
                    ON cont.state = state.id

                JOIN tbl_city city 
                    ON cont.city = city.id

                JOIN mas_office_location office_location 
                ON emp.office_location_id = office_location.id";
        
        return $query;

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

    // function to get only active employees
    public function getPaginatedActiveEmployees($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = self::$EMP_QUERY . " WHERE emp.emp_status = 1 ORDER BY emp.id ASC LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query, []);
    }

    public function getActiveEmployeesCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM tbl_employee WHERE emp_status = 1';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return $result[0]['total'] ?? 0;
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
        $required_payslip,
        $old_emp_code,
        $module,
        $username
    ) {
        try {
            // validate the data before adding to the tbl_contact table
            $f_name = trim($f_name);
            $l_name = trim($l_name);

            $contactId = $this->insertContact($f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $cityId, $stateId, $pin, $countryId, $contactTypeId, $join_date, $exit_date, $statusId, $entityId, $departmentId, $designationId, $image, $module, $username);
            if (!$contactId) {
                throw new Exception('Contact not added');
            }
            $userId = $this->insertUser($email, $statusId, $contactId, $entityId, $module, $username);
            if (!$userId) {
                throw new Exception('User not added');
            }
            $userModuleId = $this->insertUserModuleAsBaseEmployee($userId, $email, $userId, $module, $username);
            if (!$userModuleId) {
                throw new Exception('User module not added as Base Employee');
            }
            $employeeId = $this->insertEmployee($entityId, $contactId, $userId, $statusId, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $required_payslip, $old_emp_code, $userId, $module, $username);
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
        $required_payslip,
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
            $required_payslip = $this->normalizeM365Flag($required_payslip);
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
            $contactId = $this->insertContact(
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
                $module,
                $username
            );
            if (!$contactId) {
                throw new Exception('Contact not added');
            }

            $userId = $this->insertUser($email, $statusId, $contactId, $entityId, $module, $username);
            if (!$userId) {
                throw new Exception('User not added');
            }

            $userModuleId = $this->insertUserModuleAsBaseEmployee($userId, $email, $userId, $module, $username);
            if (!$userModuleId) {
                throw new Exception('User module not added as Base Employee');
            }

            $employeeId = $this->insertEmployee(
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
                $required_payslip,
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
            $employeeDetails = $this->getEmployeeById($employeeId, $module, $username);
            $employeeDetails = $employeeDetails[0] ?? null;

            // send a mail to M365 admin with cc to the hr to create the M365 account for the employee if m365 is Y, y, Yes, yes
            $mailer = new AutoMail();
            $m365AdminEmails = $this->getM365AdminEmails('hr', 'system');
            // $hrEmail = $this->getHrEmailByEntityId($entityId, $module, $username);
            if ($m365) {
                $attachments = $employeeDetails ? [$this->excelHelper->generateEmployeeXlsx($employeeDetails)] : [];
                $name = $salutationName ? $salutationName : 'Shrichandra Group Team';
                // TODO: Need to send complete details such as employee ID, department, and designation to the M365 Admin
                $keyValueData = [
                    "Message" => "A new employee record has been added for $f_name $l_name. 
                                    Please create an M365 account for this employee. Please refer to the attached Excel file for details. ",
                    "Employee Name" => $f_name . ' ' . $l_name,
                    "Employee Email" => $email,
                    "Employee Code" => $empCode,
                    // get Admin Portal URL from the env file
                    "Admin Portal URL" => $_ENV['ADMIN_PORTAL_URL'] ?? 'Not Set in Env'
                ];
                try {
                    $mailer->sendInfoEmail(
                        subject: "New Employee Record Added - M365 Account Creation Required",
                        greetings: "Dear IT Admin,",
                        name: $name,
                        keyValueArray: $keyValueData,
                        to: $m365AdminEmails,
                        cc: [], // add hr mail
                        bcc: $m365AdminEmails,
                        attachments: $attachments
                    );
                } catch (Exception $e) {
                    $this->logger->log('Failed to send email to IT Admin for M365 account creation: ' . $e->getMessage(), 'classes', $module);
                    // delete the generated Excel attachment if email sending fails
                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            if (file_exists($attachment)) {
                                unlink($attachment);
                            }
                        }
                    }
                } finally {
                    // delete the generated Excel attachment regardless of email sending success or failure
                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            if (file_exists($attachment)) {
                                unlink($attachment);
                            }
                        }
                    }
                }
            }

            // $this->conn->commitTrans();
            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to add employee record: ' . $e->getMessage(), 'classes', $module);
            return ['exception' => 'Failed to add employee record:', 'error' => $e->getMessage()];
        }
    }

    public function updateEmployeeRecord(
        $employeeId,
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
        $required_payslip,
        $m365,
        $officeLocationId,
        $old_emp_code,
        $userId,
        $module,
        $username
    ) {
        if (!$this->employeeExists($employeeId, $module, $username)) {
            throw new Exception('Employee with ID ' . $employeeId . ' does not exist.');
        }
        try {
            $hadM365 = $this->employeeHasM365Enabled($employeeId, $module, $username);
            $isActive = $this->employeeIsActive($employeeId, $module, $username);
            $contactId = $this->getContactIdForEmployee($employeeId, $module, $username);
            $userId = $this->getUserIdForEmployee($employeeId, $module, $username);
            $f_name = trim($f_name);
            $l_name = trim($l_name);
            $empType = $this->normalizeEmployeeType($empType);
            $m365 = $this->normalizeM365Flag($m365);
            $required_payslip = $this->normalizeM365Flag($required_payslip);
            $exit_date = $this->normalizeExitDate($empType, $exit_date);

            // Only personal email can be updated, not m365 email. If m365 is enabled, personal email must be different from m365 email.

            if ($m365 && !$hadM365) {
                if (strtolower(trim((string) $email)) === strtolower(trim((string) $personal_email))) {
                    throw new Exception('Personal email must be different from email when m365 is enabled');
                }
            } else {
                $personal_email = $email;
            }
            if ($empType === 'regular') {
                $contactTypeId = 2; // Employee
            } else {
                $contactTypeId = 3; // Consultant
            }


            // Check if contact exists and update it
            $contactExists = $this->checkContactExists($contactId, $module, $username);
            if (!$contactExists) {
                throw new Exception('Contact does not exist');
            }

            // normalize these values
            $pin = $pin ?? 0;
            // Pass everything as IDs, just like Excel import
            $contactId = $this->updateContact(
                $contactId,
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
                $module,
                $username
            );
            if (!$contactId) {
                throw new Exception('Contact not updated');
            }

            // Check User Record if exists
            $userExists = $this->checkUserExists($userId, $module, $username);
            if (!$userExists) {
                throw new Exception('User does not exist');
            }
            $userUpdated = $this->updateUser($userId, $email, $statusId, $contactId, $entityId, $module, $username);
            if (!$userUpdated) {
                throw new Exception('User not updated');
            }

            $employeeUpdated = $this->updateEmployee(
                $employeeId,
                $entityId,
                $statusId,
                $uan,
                $aadhar,
                $pan_no,
                $esi_no,
                $bank_name,
                $bank_account_no,
                $ifsc_code,
                $m365,
                $required_payslip,
                $officeLocationId,
                $old_emp_code,
                $userId,
                $module,
                $username
            );
            if (!$employeeUpdated) {
                throw new Exception('Employee not updated');
            }
            $salutationName = $this->entityOb->getSalutationNameByEntityId($entityId, $module, $username);
            $empCode = $this->getEmployeeCodeById($employeeId, $module, $username);
            $employeeDetails = $this->getEmployeeById($employeeId, $module, $username);
            $employeeDetails = $employeeDetails[0] ?? null;
            $m365AdminEmails = $this->getM365AdminEmails('hr', 'system');

            $mailer = new AutoMail();
            // update m365 through graph api if m365 is Y, y, Yes, yes to add the user to the Azure directory
            // only update the M365 access if the employee is active
            if ($m365 && !$hadM365 && $isActive) {
                $attachments = $employeeDetails ? [$this->excelHelper->generateEmployeeXlsx($employeeDetails)] : [];
                // TODO: implement graph api call (function) to update m365 user
                // send a mail to M365 admin with cc to the hr to create the M365 account for the employee if m365 is Y, y, Yes, yes
                // TODO: Need to send complete details such as employee ID, department, and designation to the M365 Admin
                $name = $salutationName ? $salutationName : 'Shrichandra Group Team';
                $keyValueData = [
                    "Message" => "An employee record has been updated for $f_name $l_name. Access to M365 has been enabled for this employee. 
                                    Please update the Azure directory for this employee. Please refer to the attached Excel file for details: ",
                    "Employee Name" => $f_name . ' ' . $l_name,
                    "Employee Email" => $email,
                    "Employee Code" => $empCode,
                    // get Admin Portal URL from the env file
                    "Admin Portal URL" => $_ENV['ADMIN_PORTAL_URL'] ?? 'Not Set in Env'
                ];
                try {
                    $mailer->sendInfoEmail(
                        subject: "Employee M365 Access Enabled - Azure Directory Update Required",
                        greetings: "Dear IT Admin,",
                        name: $name,
                        keyValueArray: $keyValueData,
                        to: $m365AdminEmails,
                        cc: [], // add hr mail
                        bcc: $m365AdminEmails,
                        attachments: $attachments
                    );
                } catch (Exception $e) {
                    $this->logger->log('Failed to send email to IT Admin: ' . $e->getMessage(), 'classes', $module);
                    // delete the generated Excel attachment if email sending fails
                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            if (file_exists($attachment)) {
                                unlink($attachment);
                            }
                        }
                    }
                } finally {
                    // delete the generated Excel attachment regardless of email sending success or failure
                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            if (file_exists($attachment)) {
                                unlink($attachment);
                            }
                        }
                    }
                }
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log('Failed to add employee record: ' . $e->getMessage(), 'classes', $module);
            return ['exception' => 'Failed to add employee record:', 'error' => $e->getMessage()];
        }
    }

    // function to deactivate an employee
    public function deactivateEmployee($employeeId, $module, $username)
    {
        // check whether the employee with the given employee id exists or not
        $employeeDetails = $this->getEmployeeById($employeeId, $module, $username);
        $employee = $employeeDetails[0] ?? null;
        if (!$employee) {
            return false;
        }
        // if exists, update the emp_status to 2 (In-Active)
        $this->updateEmployeeStatus($employeeId, 2, $module, $username); // 2 indicates deactivated status (In-Active)

        // update the status in tbl_contact
        $this->updateContactStatus($employeeId, 2, $module, $username); // 2 indicates deactivated status (In-Active)

        // update the status in tbl_users
        $this->updateUserStatus($employeeId, 2, $module, $username); // 2 indicates deactivated status (In-Active)

        // update the status in tbl_user_modules
        $this->updateUserModulesStatus($employeeId, 2, $module, $username); // 2 indicates deactivated status (In-Active)

        // update tbl_m365_admins if the employee is an M365 admin
        if ($this->m365AdminsOb->isM365Admin($employeeId, $module, $username)) {
            $this->m365AdminsOb->updateM365AdminStatus($employeeId, 2, $module, $username); // 2 indicates deactivated status (In-Active)
        }

        // send an email notification to M365 Admin about the deactivation (only if the employee had M365 access)
        $entityId = $employee['entity_id'];
        $hadM365 = $this->employeeHasM365Enabled($employeeId, $module, $username);
        $salutationName = $this->entityOb->getSalutationNameByEntityId($entityId, $module, $username);
        $empCode = $this->getEmployeeCodeById($employeeId, $module, $username);
        $m365AdminEmails = $this->getM365AdminEmails('hr', 'system');

        $mailer = new AutoMail();
        // update m365 through graph api if m365 is Y, y, Yes, yes
        if ($hadM365) {
            // TODO: implement graph api call (function) to update m365 user
            // send a mail to M365 admin with cc to the hr to create the M365 account for the employee if m365 is Y, y, Yes, yes
            $name = $salutationName ? $salutationName : 'Shrichandra Group Team';
            $keyValueData = [
                "Message" => "An employee record has been deactivated for " . $employee['first_name'] . ' ' . $employee['last_name'] . ". 
                                Please update (deactivate) the Azure directory for this employee. The details are as follows: ",
                "Employee Name" => $employee['first_name'] . ' ' . $employee['last_name'],
                "Employee Email" => $employee['email'],
                "Employee Code" => $empCode,
                // get Admin Portal URL from the env file
                "Admin Portal URL" => $_ENV['ADMIN_PORTAL_URL'] ?? 'Not Set in Env'
            ];
            try {
                $mailer->sendInfoEmail(
                    subject: "Employee M365 Access Deactivated - Azure Directory Update Required",
                    greetings: "Dear IT Admin,",
                    name: $name,
                    keyValueArray: $keyValueData,
                    to: $m365AdminEmails,
                    cc: [], // add hr mail
                    bcc: $m365AdminEmails,
                );
            } catch (Exception $e) {
                $this->logger->log('Failed to send email to IT Admin: ' . $e->getMessage(), 'classes', $module);
            }
        }
        return true;
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
                $result = $this->m365Validation($row['email'], $module, $username);
                // true - valid, false - invalid (duplicate)
                if (!$result) {
                    $duplicateRowsInExcelFile[] = [
                        'row_number' => $rowNumber,
                        'Error' => "Row has duplicate or Invalid email for M365. Please check the email address.",
                        'data' => [
                            'email' => $row['email'],
                        ]
                    ];
                    $rowNumber++;
                    continue;
                }
                $key = strtolower($row['email']) . '_' . strtolower($row['entity_id']);
            } else {
                $key = strtolower($row['old_emp_code']) . '_' . strtolower($row['entity_id']);
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
        $m365ExistingRows = $this->conn->runQuery("SELECT user.email AS email, ent.id AS entity FROM $tableName emp 
                                JOIN tbl_entity ent ON emp.entity_id = ent.id 
                                JOIN tbl_users user on user.id = emp.user_id 
                                WHERE emp.m365 = 1");
        $m365ExistingRowsMap = [];

        foreach ($m365ExistingRows as $m365ExistingRow) {
            $key = strtolower($m365ExistingRow['email']) . '_' . strtolower($m365ExistingRow['entity']);
            $m365ExistingRowsMap[$key] = true;
        }

        // Existing non-M365 employees
        $nonM365ExistingRows = $this->conn->runQuery("SELECT emp.old_emp_code AS old_emp_code , ent.id AS entity
                                FROM $tableName emp 
								JOIN tbl_entity ent ON ent.id = emp.entity_id 
                                WHERE emp.m365 = 0");
        $nonM365ExistingRowsMap = [];
        foreach ($nonM365ExistingRows as $nonM365ExistingRow) {
            $key = strtolower($nonM365ExistingRow['old_emp_code']) . '_' . strtolower($nonM365ExistingRow['entity']);
            $nonM365ExistingRowsMap[$key] = true;
        }


        $lookupCache = new LookupCache($this->conn, $this->logger);
        $lookupCache->load();

        foreach ($cleanedRows as $row) {
            // find the array length of cleanedRows and store it in a variable
            // $row means the column in the db (so map with the column names in the db)


            if (strtolower(trim($row['emp_status'])) !== 'active' && strtolower(trim($row['emp_status'])) !== 'in-active' && strtolower(trim($row['emp_status'])) !== 'suspended' && strtolower(trim($row['emp_status'])) !== 'blocked') {

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

            if (!isset($row['required_payslip']) || !in_array(strtolower(trim((string) $row['required_payslip'])), ['y', 'yes', 'n', 'no'], true)) {
                throw new Exception('Invalid value for required_payslip: ' . ($row['required_payslip'] ?? ''));
            }

            if (strtolower(trim($row['m365'])) === 'y' || strtolower(trim($row['m365'])) === 'yes') {
                $m365 = true;
            } else {
                $m365 = false;
            }

            $required_payslip = $this->normalizeM365Flag($row['required_payslip']);


            if ($m365) {
                $key = strtolower($row['email']) . '_' . strtolower($row['entity_id']);
                if (isset($m365ExistingRowsMap[$key])) {
                    $duplicateRowsInDb[] = ['data' => [
                        'email' => $row['email'],
                        'entity_id' => $row['entity_id']
                    ]];
                    continue;
                }
            } else {
                $key = strtolower($row['old_emp_code']) . '_' . strtolower($row['entity_id']);
                if (isset($nonM365ExistingRowsMap[$key])) {
                    $duplicateRowsInDb[] = ['data' => [
                        'old_emp_code' => $row['old_emp_code'],
                        'entity_id' => $row['entity_id']
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
            // $entityId = intval($lookupCache->getEntityId(strtolower(trim($row['entity_code']))));
            // if (!$entityId) {
            //     throw new Exception('Entity not found: ' . $row['entity_code']);
            // }

            $entityId = intval($row['entity_id']);
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
            $row['personal_email'] = $row['personal_email'] ?? $row['email'] ? $row['email'] : null;
            $officeLocationId = intval($row['office_location_id'] ?? 0);
            // insert the data using the addEmployeeRecordForExcelImport function
            $this->addEmployeeRecordForExcelImport(
                trim($row['f_name']),
                trim($row['l_name']),
                $dateOfBirth,
                strtolower(trim($row['email'])),
                strtolower(trim($row['personal_email'])),
                $row['mobile'],
                trim($row['add1']),
                trim($row['add2']),
                $cityId,
                $stateId,
                $countryId,
                $row['pin'],
                $officeLocationId,
                $contactTypeId,
                $joinDate,
                $exitDate,
                $statusId,
                $entityId,
                $departmentId,
                $designationId,
                $row['image'],
                trim($row['uan']),
                trim($row['aadhar']),
                trim($row['pan_no']),
                trim($row['esi_no']),
                trim($row['bank_name']),
                trim($row['bank_account_no']),
                trim($row['ifsc_code']),
                $m365,
                $required_payslip,
                trim($row['old_emp_code']),
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

    public function insertContact(
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
        $module,
        $username
    ) {
        $query = 'INSERT INTO tbl_contact (f_name, l_name, dob, email, personal_email, mobile, add1, add2, city, state, pin, country, contacttype_id, join_date, exit_date, emp_status, entity_id, department, designation, image, createdBy) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $username], 'classes', $module, $username);
        $contactId = $this->conn->insert($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $username], 'Contact added');
        return $contactId;
    }

    public function updateContact(
        $contactId,
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
        $module,
        $username
    ) {
        try {
            $query = 'UPDATE tbl_contact SET f_name = ?, l_name = ?, dob = ?, email = ?, personal_email = ?, 
                        mobile = ?, add1 = ?, add2 = ?, city = ?, state = ?, pin = ?, country = ?, contacttype_id = ?, 
                        join_date = ?, exit_date = ?, emp_status = ?, entity_id = ?, department = ?, designation = ?, 
                        image = ?, last_updated = ? WHERE id = ?';
            $this->logger->logQuery($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $username, $contactId], 'classes', $module, $username);
            $contactId = $this->conn->update($query, [$f_name, $l_name, $birth_date, $email, $personal_email, $mobile, $add1, $add2, $city, $state, $pin, $country, $contacttype_id, $join_date, $exit_date, $emp_status, $entity_id, $department, $designation, $image, $username, $contactId], 'Contact updated');
            return true;
        } catch (Exception $e) {
            throw new Exception('Failed to update contact: ' . $e->getMessage());
        }
    }

    public function insertUser($email, $user_status, $contact_id, $entity_id, $module, $username)
    {
        $user_name = $email;
        // generate a random password
        $password = bin2hex(random_bytes(8));
        $password = password_hash($password, PASSWORD_BCRYPT);
        $code = 0;
        $status = 'verified';
        $manager = '';
        $manager_email = '';

        $query = 'INSERT INTO tbl_users (user_name, email, password, user_status, contact_id, code, status, entity_id, manager, manager_email, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$user_name, $email, $password, $user_status, $contact_id, $code, $status, $entity_id, $manager, $manager_email, $username], 'classes', $module, $username);
        $userId = $this->conn->insert($query, [$user_name, $email, $password, $user_status, $contact_id, $code, $status, $entity_id, $manager, $manager_email, $username], 'User added');
        return $userId;
    }

    public function updateUser($user_id, $email, $user_status, $contact_id, $entity_id, $module, $username)
    {
        try {
            $query = 'UPDATE tbl_users SET user_status = ?, contact_id = ?, entity_id = ?, last_updatedBy = ? WHERE id = ?';
            $this->logger->logQuery($query, [$user_status, $contact_id, $entity_id, $username, $user_id], 'classes', $module, $username);
            $this->conn->update($query, [$user_status, $contact_id, $entity_id, $username, $user_id], 'User updated');
            return true;
        } catch (Exception $e) {
            throw new Exception('Failed to update user: ' . $e->getMessage());
        }
    }

    public function insertUserModuleAsBaseEmployee($user_id, $email, $userId, $module, $username)
    {
        $module_id = 2;
        $user_role_id = 5;
        $enabled = 1;

        $query = 'INSERT INTO tbl_user_modules (user_id, email, module_id, user_role_id, enabled, created_by) VALUES (?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$user_id, $email, $module_id, $user_role_id, $enabled, $userId], 'classes', $module, $username);
        $userModuleId = $this->conn->insert($query, [$user_id, $email, $module_id, $user_role_id, $enabled, $userId], 'User module added as Base Employee');
        return $userModuleId;
    }



    public function insertEmployee($entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $required_payslip, $old_emp_code, $userId, $module, $username)
    {

        $m365 = ($m365 === true || $m365 === 1 || in_array(strtolower(trim((string)$m365)), ['y', 'yes', '1', 'true'], true)) ? 1 : 0;
        $required_payslip = $this->normalizeM365Flag($required_payslip) ? 1 : 0;
        $officeLocationId = $officeLocationId ?: 1; // default to 1 if not provided
        // if the employee does not exist, add it
        $emp_code = $this->generateEmployeeCode($entity_id, $module, $username);
        $query = 'INSERT INTO tbl_employee (emp_code, entity_id,
            contact_id, user_id, emp_status, 
            uan, aadhar, pan_no, esi_no, bank_name, 
            bank_account_no, ifsc_code, 
            m365, office_location_id, required_payslip, old_emp_code, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->logger->logQuery($query, [$emp_code, $entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $required_payslip, $old_emp_code, $userId], 'classes', $module, $username);
        $employeeId = $this->conn->insert($query, [$emp_code, $entity_id, $contact_id, $user_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $officeLocationId, $required_payslip, $old_emp_code, $userId], 'Employee added');
        return $employeeId;
    }



    public function updateEmployee($employeeId, $entity_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $required_payslip, $officeLocationId, $old_emp_code, $userId, $module, $username)
    {
        try {
            // Check if the employee exists
            if (!$this->employeeExistsById($employeeId, $module, $username)) {
                throw new Exception('Employee with  ' . $employeeId . ' does not exist.');
            }

            // Normalize m365 value
            $m365 = ($m365 === true || $m365 === 1 || in_array(strtolower(trim((string)$m365)), ['y', 'yes', '1', 'true'], true)) ? 1 : 0;
            $required_payslip = $this->normalizeM365Flag($required_payslip) ? 1 : 0;
            $officeLocationId = $officeLocationId ?: 1; // default to 1 if not provided

            // Update the employee record
            $query = 'UPDATE tbl_employee SET entity_id = ?, emp_status = ?, uan = ?, 
                        aadhar = ?, pan_no = ?, esi_no = ?, bank_name = ?, 
                        bank_account_no = ?, ifsc_code = ?, m365 = ?, required_payslip = ?, 
                        office_location_id = ?, old_emp_code = ?, 
                        updatedBy = ? WHERE id = ?';
            $this->logger->logQuery($query, [$entity_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $required_payslip, $officeLocationId, $old_emp_code, $userId, $employeeId], 'classes', $module, $username);
            $this->conn->update($query, [$entity_id, $emp_status, $uan, $aadhar, $pan_no, $esi_no, $bank_name, $bank_account_no, $ifsc_code, $m365, $required_payslip, $officeLocationId, $old_emp_code, $userId, $employeeId], 'Employee updated');
            return true;
        } catch (Exception $e) {
            throw new Exception('Failed to update employee: ' . $e->getMessage());
        }
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


    public function checkM365UserExists($email, $module, $username)
    {
        $query = 'SELECT first_name, last_name FROM tbl_m365_users WHERE mail = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        if ($result) {
            return $result;
        }
        return null;
    }

    public function m365Validation($email, $module, $username)
    {
        // check if the email exists in tbl_m365_users
        $query = 'SELECT 1 FROM tbl_m365_users WHERE mail = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        // since the email is not present in tbl_m365_users, it is not valid to add the employee record with m365 enabled. So return false.
        if (!$result) {
            return false;
        }

        // check if the email exists in tbl_employee, tbl_contact, tbl_users, tbl_user_modules
        $query = 'SELECT 1 FROM tbl_employee emp
                    JOIN tbl_contact cont ON emp.contact_id = cont.id
                    JOIN tbl_users usr ON emp.user_id = usr.id
                    JOIN tbl_user_modules um ON usr.id = um.user_id
                    WHERE cont.email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        // if the email is present in tbl_employee, tbl_contact, tbl_users, tbl_user_modules, 
        // it is not valid to add the employee record with m365 enabled because it is treated as a duplicate record. 
        // So return false stating the validation failed. If the email is not present in tbl_employee, tbl_contact, tbl_users, tbl_user_modules and present in tbl_m365_users, 
        // it is valid to add the employee record with m365 enabled. So return true stating the validation passed.
        if ($result) {
            return false;
        }
        return true;
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

    public function getM365AdminEmails($module, $username)
    {
        $query = 'SELECT user.email FROM tbl_m365_admins mad JOIN tbl_employee emp 
                    ON emp.id = mad.employee_id
                    JOIN tbl_users user ON emp.user_id=user.id 
                    WHERE mad.status = 1';
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

    public function employeeExists($employeeId, $module, $username)
    {
        $query = 'SELECT 1 FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$employeeId]);
        return (bool)$result;
    }

    private function employeeHasM365Enabled($employeeId, $module, $username)
    {
        $query = 'SELECT m365 FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $employee = $this->conn->runSingle($query, [$employeeId]);
        return isset($employee['m365']) && (int) $employee['m365'] === 1;
    }


    public function getContactByEmail($email, $module, $username)
    {
        $query = 'SELECT * FROM tbl_contact WHERE email = ?';
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return $result ?: null;
    }

    public function checkUserExists($userId, $module, $username)
    {
        $query = 'SELECT 1 FROM tbl_users WHERE id = ?';
        $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$userId]);
        return (bool)$result;
    }

    public function employeeExistsById($employeeId, $module, $username)
    {
        $query = 'SELECT 1 FROM tbl_employee WHERE id = ?';
        // complete the function
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$employeeId]);
        return (bool)$result;
    }

    public function checkContactExists($contactId, $module, $username)
    {
        $query = 'SELECT 1 FROM tbl_contact WHERE id = ?';
        $this->logger->logQuery($query, [$contactId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$contactId]);
        return (bool)$result;
    }

    public function getUserIdForEmployee($employeeId, $module, $username)
    {
        $query = 'SELECT user_id FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$employeeId]);
        return $result['user_id'] ?? null;
    }

    public function getContactIdForEmployee($employeeId, $module, $username)
    {
        $query = 'SELECT contact_id FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$employeeId]);
        return $result['contact_id'] ?? null;
    }

    public function updateEmployeeStatus($employeeId, $statusId, $module, $username)
    {
        $query = 'UPDATE tbl_employee SET emp_status = ?, updatedBy = ? WHERE id = ?';
        $params = [$statusId, $username, $employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->update($query, $params, 'Employee status updated');
    }

    public function updateContactStatus($employeeId, $statusId, $module, $username)
    {
        $query = 'UPDATE tbl_contact cont
                    JOIN tbl_employee emp ON emp.contact_id = cont.id
                    SET cont.emp_status = ?, cont.last_updated = ?
                    WHERE emp.id = ?';
        $params = [$statusId, $username, $employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->update($query, $params, 'Contact status updated');
    }

    public function updateUserStatus($employeeId, $statusId, $module, $username)
    {
        $query = 'UPDATE tbl_users usr
                    JOIN tbl_employee emp ON emp.user_id = usr.id
                    SET usr.user_status = ?, usr.last_updatedBy = ?
                    WHERE emp.id = ?';
        $params = [$statusId, $username, $employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->update($query, $params, 'User status updated');
    }

    public function updateUserModulesStatus($employeeId, $statusId, $module, $username)
    {
        $enabled = (int) $statusId === 1 ? 1 : 0;
        $query = 'UPDATE tbl_user_modules user_modules
                    JOIN tbl_employee emp ON emp.user_id = user_modules.user_id
                    SET user_modules.enabled = ?, user_modules.last_updated = ?
                    WHERE emp.id = ?';
        $params = [$enabled, $username, $employeeId];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->update($query, $params, 'User module status updated');
    }

    public function employeeIsActive($employeeId, $module, $username)
    {
        $query = 'SELECT emp_status FROM tbl_employee WHERE id = ?';
        $this->logger->logQuery($query, [$employeeId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$employeeId]);
        // if emp_status = 1 return true else false
        if (isset($result['emp_status']) && $result['emp_status'] === 1) {
            return true;
        }
        return false;
    }

    public function normalizeBoolean($value)
    {
        return in_array(strtolower(trim((string) $value)), ['y', 'yes', '1', 'true'], true);
    }

    public function updateM365() {}
}
