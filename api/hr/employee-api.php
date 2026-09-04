<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/hr/Employee.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';
require_once __DIR__ . '../../../classes/utils/ExcelHelper.php';
require_once __DIR__ . '../../../classes/utils/ExcelTemplateHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';



authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);


$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$regExp = '/^[a-zA-Z0-9\s]+$/';

$employeeOb = new Employee();
$employeeConfigFilePath = $_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/employee.ini';
$excelHelper = new ExcelHelper($employeeConfigFilePath);
$excelTemplateHelper = new ExcelTemplateHelper($employeeConfigFilePath);
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest user';
$module = 'Human Resource Management';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");
        try {
            if (isset($_GET['download-template']) && $_GET['download-template'] == 'true') {
                $excelTemplateHelper->generateTemplate();
                http_response_code(200);
                echo json_encode(["message" => "Template generated successfully."]);
                break;
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        // get employee with id 
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $employee = $employeeOb->getEmployeeById($id, $module, $username);
            http_response_code(200);
            echo json_encode(['employee' => $employee]);
            break;
        }

        // get employee with email
        if (isset($_GET['email'])) {
            $email = $_GET['email'];
            $employee = $employeeOb->getEmployeeByEmail($email, $module, $username);
            http_response_code(200);
            echo json_encode(['employee' => $employee]);
            break;
        }

        if (isset($_GET['check-email-exists'])) {
            $email = $_GET['check-email-exists'];
            $employee = $employeeOb->checkM365UserExists($email, $module, $username);
            if ($employee == null) {
                http_response_code(200);
                echo json_encode(['exists' => false]);
            } else {
                http_response_code(200);
                echo json_encode(['exists' => true]);
            }
            break;
        }

        // paginated employees response
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        $employees = $employeeOb->getPaginatedEmployees($offset, $limit, $module, $username);
        $total = $employeeOb->getEmployeesCount($module, $username);
        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'employees' => $employees,
        ];
        http_response_code(200);
        echo json_encode($response);
        break;

    case 'POST':
        $logger->log("POST request received");
        // file upload for employee import
        if (isset($_FILES['file'])) {
            try {
                $file = $_FILES['file'];
                $excelHelper->createTemporaryTable();
                $batchId = $excelHelper->importExcelToTemporaryTable($file);
                $errorReport = $employeeOb->importDataFromExcel($batchId, $module, $username);
                $excelHelper->cleanTemporaryTable($batchId);
                http_response_code(200);
                echo json_encode(["message" => "Data imported and inserted successfully.", "errors" => $errorReport]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;
        }

        // form data for employee import - use addEmployeeRecordForManualImport function
        // removed email, contacttype(auto set based on employee)
        // if m365 is true, then email and personal email are different
        // if m365 is false, then email and personal email are same

        if (
            isset($input['f_name']) && isset($input['l_name']) && isset($input['dob']) && isset($input['mobile']) && isset($input['email']) &&
            isset($input['city']) && isset($input['state']) && array_key_exists('pin', $input) && isset($input['country']) && array_key_exists('add1', $input) && array_key_exists('add2', $input) &&
            isset($input['personal_email']) && isset($input['join_date']) && isset($input['emp_type']) &&
            isset($input['emp_status']) && isset($input['entity_id']) && isset($input['department_id']) &&
            isset($input['designation_id']) && array_key_exists('image', $input) && isset($input['uan']) && isset($input['aadhar']) &&
            isset($input['pan_no']) && isset($input['esi_no']) && isset($input['bank_name']) && isset($input['bank_account_no']) &&
            isset($input['ifsc_code']) && isset($input['m365']) && isset($input['old_emp_code'])
        ) {
            $f_name = trim($input['f_name']);
            $l_name = trim($input['l_name']);
            $dob = trim($input['dob']);
            $email = trim($input['email']);
            $personal_email = trim($input['personal_email']);
            $mobile = trim($input['mobile']);
            $add1 = trim($input['add1']) ?? null;
            $add2 = trim($input['add2']) ?? null;
            $city = intval(trim($input['city']));
            $state = intval(trim($input['state']));
            $pin = trim($input['pin']) ?? null;
            $country = intval(trim($input['country']));
            $join_date = trim($input['join_date']);
            $exit_date = isset($input['exit_date']) ? trim((string) $input['exit_date']) : null;
            $emp_type = $input['emp_type'];
            // domain name is in the entity table, so no need to pass it here, we can get it from the entity id
            $emp_status = intval(trim($input['emp_status']));
            $entity_id = intval(trim($input['entity_id']));
            $department_id = intval(trim($input['department_id']));
            $designation_id = intval(trim($input['designation_id']));
            $image = trim($input['image']);
            $uan = trim($input['uan']);
            $aadhar = trim($input['aadhar']);
            $pan_no = trim($input['pan_no']);
            $esi_no = trim($input['esi_no']);
            $bank_name = trim($input['bank_name']);
            $bank_account_no = trim($input['bank_account_no']);
            $ifsc_code = trim($input['ifsc_code']);
            $m365 = trim($input['m365']);
            $old_emp_code = trim($input['old_emp_code']);
            // $module = trim($input['module']);
            // $username = trim($input['username']);

            // check duplicate employee record based on email and entity_id
            if ($employeeOb->checkDuplicateEmployeeByEmail($email, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this email already exists for the given entity."]);
                break;
            }
            // check duplicate employee record based on personal email and entity_id
            if ($employeeOb->checkDuplicateEmployeeByPersonalEmail($personal_email, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this personal email already exists for the given entity."]);
                break;
            }

            // check duplicate employee record based on aadhar and entity_id
            if ($employeeOb->checkDuplicateEmployeeByAadhar($aadhar, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this Aadhar number already exists for the given entity."]);
                break;
            }

            // check duplicate employee record based on pan_no and entity_id
            if ($employeeOb->checkDuplicateEmployeeByPan($pan_no, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this PAN number already exists for the given entity."]);
                break;
            }

            // check duplicate employee record based on mobile and entity_id
            if ($employeeOb->checkDuplicateEmployeeByMobile($mobile, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this mobile number already exists for the given entity."]);
                break;
            }

            // check duplicate employee record based on uan and entity_id
            if ($employeeOb->checkDuplicateEmployeeByUAN($uan, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this UAN number already exists for the given entity."]);
                break;
            }

            // check duplicate employee record based on bank_account_no and entity_id
            if ($employeeOb->checkDuplicateEmployeeByBankAccount($bank_account_no, $entity_id)) {
                http_response_code(400);
                echo json_encode(["error" => "Employee with this bank account number already exists for the given entity."]);
                break;
            }

            $officeLocationId = isset($input['office_location_id']) ? intval(trim($input['office_location_id'])) : null;

            $employee = $employeeOb->addEmployeeRecordForManualImport(
                $f_name,
                $l_name,
                $dob,
                $email,
                $personal_email,
                $mobile,
                $add1,
                $add2,
                $city,
                $state,
                $country,
                $pin,
                $join_date,
                $exit_date,
                $emp_type,
                $emp_status,
                $entity_id,
                $department_id,
                $designation_id,
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
                $username,
                $module,
                $username
            );
            if (is_array($employee) && isset($employee['error'])) {
                http_response_code(400);
                echo json_encode(['error' => $employee['error']]);
                break;
            }
            http_response_code(200);
            echo json_encode(['message' => 'Employee record added successfully']);
            break;
        }
        http_response_code(400);
        echo json_encode(["error" => "Invalid request"]);
        break;

    case 'PUT':
        $logger->log("PUT request received");
        $id = $_GET['id'] ?? null;
        if ($id === null) {
            http_response_code(400);
            echo json_encode(["error" => "Missing employee ID"]);
            break;
        }

        if (
            isset($input['f_name'], $input['l_name'], $input['dob'], $input['mobile'], $input['email']) &&
            isset($input['city'], $input['state'], $input['country'], $input['personal_email'], $input['join_date'], $input['emp_type']) &&
            isset($input['emp_status'], $input['entity_id'], $input['department_id'], $input['designation_id'], $input['uan']) &&
            isset($input['aadhar'], $input['pan_no'], $input['esi_no'], $input['bank_name'], $input['bank_account_no']) &&
            isset($input['ifsc_code'], $input['m365'], $input['old_emp_code']) &&
            array_key_exists('pin', $input) && array_key_exists('add1', $input) && array_key_exists('add2', $input) && array_key_exists('image', $input)
        ) {
            try {
                $employee = $employeeOb->updateEmployeeRecord(
                    intval($id),
                    trim($input['f_name']),
                    trim($input['l_name']),
                    trim($input['dob']),
                    trim($input['email']),
                    trim($input['personal_email']),
                    trim($input['mobile']),
                    $input['add1'] === null ? null : trim($input['add1']),
                    $input['add2'] === null ? null : trim($input['add2']),
                    intval($input['city']),
                    intval($input['state']),
                    intval($input['country']),
                    $input['pin'] === null ? null : trim((int) $input['pin']),
                    trim($input['join_date']),
                    isset($input['exit_date']) ? trim((string) $input['exit_date']) : null,
                    $input['emp_type'],
                    intval($input['emp_status']),
                    intval($input['entity_id']),
                    intval($input['department_id']),
                    intval($input['designation_id']),
                    $input['image'] === null ? null : trim($input['image']),
                    trim($input['uan']),
                    trim($input['aadhar']),
                    trim($input['pan_no']),
                    trim($input['esi_no']),
                    trim($input['bank_name']),
                    trim($input['bank_account_no']),
                    trim($input['ifsc_code']),
                    $input['m365'],
                    isset($input['office_location_id']) ? intval($input['office_location_id']) : null,
                    trim($input['old_emp_code']),
                    $username,
                    $module,
                    $username
                );

                if (is_array($employee) && isset($employee['error'])) {
                    http_response_code(400);
                    echo json_encode(['error' => $employee['error']]);
                    break;
                }

                http_response_code(200);
                echo json_encode(['message' => 'Employee record updated successfully']);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;
        }
}
