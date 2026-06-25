<?php

require_once __DIR__ . "/ExcelHelper.php";
require_once __DIR__ . "/MainInsertionSample.php";
require_once __DIR__. '../../authentication/middle.php';
require_once __DIR__ . '../../Logger.php';
require_once __DIR__ . '../../authentication/LoginUser.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') { 
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed. Please use POST."]);
    exit;
}

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$regExp = '/^[a-zA-Z\s]+$/';
//Front End authorization as Trusted Hosts.

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$excelImporter = new ExcelHelper(__DIR__ . "/../../excel-config/hr/employee.ini");
$insertToMainTable = new MainInsertionSample();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'POST':
        try {
            if (!isset($_FILES['file'])) {
                throw new Exception("No file uploaded.");
            }
            $file = $_FILES['file'];
            $excelImporter->createTemporaryTable();
            $batchId = $excelImporter->importExcelToTemporaryTable($file);
            $errorReport = $insertToMainTable->insertDataFromTempTable($batchId);
            $excelImporter->cleanTemporaryTable($batchId);
            http_response_code(200);
            echo json_encode(["message" => "Data imported and inserted successfully.", "errors" => $errorReport]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed. Please use POST."]);
        break;
}
