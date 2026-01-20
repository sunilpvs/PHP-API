<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/vms/Msme.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';

// Validate login and authenticate JWT
authenticateJWT();

// Config & logger
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

// Get request info
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Initialize objects
$msme = new Msme();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'Admin';

switch ($method) {

    case 'GET':
        if (isset($_GET['reference_id'])) {
            $reference_id = $_GET['reference_id'];
            $result = $msme->getMsmeByReference($reference_id, $module, $username);
   
            $response = ['msme' => $result];
            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        }
        else {
            $result = $msme->getAllMsme($module, $username);
   
            $response = ['msmes' => $result];
            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        }


        break;

    case 'POST':
        if (!isset($_GET['reference_id'])) {
            $error = ["error" => "reference_id is required"];
            http_response_code(400);
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $duplicateCheck = $msme->duplicateMsmeCheck($_GET['reference_id']);
        if ($duplicateCheck) {
            $error = ["error" => "MSME record already exists for the given reference id"];
            http_response_code(409);
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];

        if($input['registered_under_msme'] == 0) {
            $input['udyam_registration_number'] = NULL;
        }

        $result = $msme->insertMsme(
            $reference_id, 
            $input['registered_under_msme'], 
            $input['udyam_registration_number'], 
            $input['category'], 
            $module, 
            $username
        );

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Msme Details added successfully", "id" => (int)$result];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to add vendor"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

    case 'PUT':
        if (!isset($_GET['reference_id'])) {
            $error = ["error" => "reference id is required"];
            http_response_code(400);
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];
        $msmeData = $msme->getMsmeByReference($reference_id, $module, $username);
        if (!$msmeData) {
            $error = ["error" => "MSME record not found for the given reference id"];
            http_response_code(404);
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }
        $msmeid = $msmeData['msme_id'];
        if($input['registered_under_msme'] == 0) {
            $input['udyam_registration_number'] = NULL;
        }

        $updateResult = $msme->updateMsme(
            $msmeid, 
            $input['registered_under_msme'],
            $input['udyam_registration_number'],
            $input['category'], 
            $module, 
            $username
        );

        $response = $updateResult !== false
            ? ["message" => $updateResult > 0 ? "MSME updated successfully" : "No changes made"]
            : ["error" => "Failed to update MSME"];

        http_response_code(isset($response['error']) ? 400 : 200);
        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    default:
        $error = ["error" => "Method not allowed"];
        http_response_code(405);
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
?>
