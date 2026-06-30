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
$employeeConfigFilePath = __DIR__ . '../../../excel-config/hr/employee.ini';
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
                // $excelHelper->cleanTemporaryTable($batchId);
                http_response_code(200);
                echo json_encode(["message" => "Data imported and inserted successfully.", "errors" => $errorReport]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;
        }

        // form data for employee import - use addEmployeeRecordForManualImport function
        if (
            isset($input['f_name']) && isset($input['l_name']) && isset($input['dob']) && isset($input['email']) && isset($input['mobile']) &&
            isset($input['city']) && isset($input['state']) && isset($input['pin']) && isset($input['country']) && isset($input['add1']) && isset($input['add2']) &&
            isset($input['personal_email']) && isset($input['contacttype_id']) && isset($input['join_date']) && isset($input['exit_date']) &&
            isset($input['emp_status']) && isset($input['entity_id']) && isset($input['department_id']) &&
            isset($input['designation_id']) && isset($input['image']) && isset($input['uan']) && isset($input['aadhar']) &&
            isset($input['pan_no']) && isset($input['esi_no']) && isset($input['bank_name']) && isset($input['bank_account_no']) &&
            isset($input['ifsc_code']) && isset($input['m365']) && isset($input['old_emp_code']) && isset($input['created_by']) &&
            isset($input['module']) && isset($input['username'])
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
            $contacttype_id = intval(trim($input['contacttype_id']));
            $join_date = trim($input['join_date']);
            $exit_date = trim($input['exit_date']);
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
            $module = trim($input['module']);
            $username = trim($input['username']);

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
                $pin,
                $country,
                $contacttype_id,
                $join_date,
                $exit_date,
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
                $old_emp_code,
                $username,
                $module,
                $username
            );
            http_response_code(200);
            echo json_encode(['employee' => $employee]);
            break;
        }
        http_response_code(400);
        echo json_encode(["error" => "Invalid request"]);
        break;
}
