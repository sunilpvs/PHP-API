<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/hr/Salaries.php';
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

$salaryOb = new Salaries();
$salaryConfigFilePath = __DIR__ . '../../../excel-config/hr/salaries.ini';
$incrementsConfigFilePath = __DIR__ . '../../../excel-config/hr/increments.ini';
$salaryExcelHelper = new ExcelHelper($salaryConfigFilePath);
$salaryExcelTemplateHelper = new ExcelTemplateHelper($salaryConfigFilePath);
$incrementsTemplateHelper = new ExcelTemplateHelper($incrementsConfigFilePath);
$incrementsExcelHelper = new ExcelHelper($incrementsConfigFilePath);
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest user';
$module = 'Human Resource Management';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");
        try {
            if (isset($_GET['download-salary-template']) && $_GET['download-salary-template'] == 'true') {
                $salaryExcelTemplateHelper->generateTemplate();
                http_response_code(200);
                echo json_encode(["message" => "Salary template generated successfully."]);
                break;
            }
            if (isset($_GET['download-increments-template']) && $_GET['download-increments-template'] == 'true') {
                $incrementsTemplateHelper->generateTemplate();
                http_response_code(200);
                echo json_encode(["message" => "Increments template generated successfully."]);
                break;
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        // get salary with id 
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $salary = $salaryOb->getSalaryById($id, $module, $username);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        } 

         // get latest salary by old employee code
         if (isset($_GET['old_emp_code']) && isset($_GET['latest']) && $_GET['latest'] == 'true') {
            $old_emp_code = $_GET['old_emp_code'];
            $salary = $salaryOb->getLatestSalaryByOldEmployeeCode($old_emp_code);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }

        // get latest salary by employee code
        if (isset($_GET['emp_code']) && isset($_GET['latest']) && $_GET['latest'] == 'true') {
            $emp_code = $_GET['emp_code'];
            $salary = $salaryOb->getLatestSalaryByEmployeeCode($emp_code);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }
        
        // get salary by old employee code
        if (isset($_GET['old_emp_code'])) {
            $old_emp_code = $_GET['old_emp_code'];
            $salary = $salaryOb->getSalaryByOldEmployeeCode($old_emp_code, $module, $username);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }

        // get salary by employee code
        if (isset($_GET['employee_code'])) {
            $employee_code = $_GET['employee_code'];
            $salary = $salaryOb->getSalaryByEmployeeCode($employee_code, $module, $username);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }

        // get salary by effective from and effective to
        if (isset($_GET['from']) && isset($_GET['to'])) {
            $effective_from = $_GET['from'];
            $effective_to = $_GET['to'];
            $salary = $salaryOb->getSalaryByEffectiveFromAndEffectiveTo($effective_from, $effective_to, $module, $username);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }

        // get current effective from date
        if (isset($_GET['current_effective_from'])) {
            $current_effective_from = $_GET['current_effective_from'];
            $salary = $salaryOb->getCurrentEffectiveFromDate($current_effective_from, $module, $username);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }

        // get latest salary by employee id
        if (isset($_GET['employee_id'])) {
            $employee_id = $_GET['employee_id'];
            $salary = $salaryOb->getLatestSalaryByEmployeeId($employee_id, $module, $username);
            http_response_code(200);
            echo json_encode(['salary' => $salary]);
            break;
        }

        // paginated employees response
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        $salaries = $salaryOb->getPaginatedSalaries($offset, $limit, $module, $username);
        $total = $salaryOb->getSalariesCount($module, $username);
        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'salaries' => $salaries,
        ];
        http_response_code(200);
        echo json_encode($response);
        break;

    case 'POST':
        $logger->log("POST request received");
        // file upload for salary import
        if (isset($_POST['import-type']) && $_POST['import-type'] == 'salaries') {
            try {
                $file = $_FILES['file'];
                $salaryExcelHelper->createTemporaryTable();
                $batchId = $salaryExcelHelper->importExcelToTemporaryTable($file);
                $errorReport = $salaryOb->importSalariesFromExcel($batchId, $module, $username);
                $salaryExcelHelper->cleanTemporaryTable($batchId);
                http_response_code(200);
                echo json_encode(["message" => "Data imported and inserted successfully.", "errors" => $errorReport]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["error" => $e->getMessage()]);
            }
        }
        if (isset($_POST['import-type']) && $_POST['import-type'] == 'increments') {
            try {
                $file = $_FILES['file'];
                $incrementsExcelHelper->createTemporaryTable();
                $batchId = $incrementsExcelHelper->importExcelToTemporaryTable($file);
                $errorReport = $salaryOb->importIncrementsFromExcel($batchId, $module, $username);
                $incrementsExcelHelper->cleanTemporaryTable($batchId);
                http_response_code(200);
                echo json_encode(["message" => "Data imported and inserted successfully.", "errors" => $errorReport]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["error" => $e->getMessage()]);
            }
        }

}
