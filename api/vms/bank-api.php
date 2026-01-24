<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Bank.php';

// Authenticate using JWT
authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$bankOb = new Bank();

$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'BANK';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['reference_id'])) {
            $reference_id = $_GET['reference_id'];
            $bank = $bankOb->getBankDetailsByReference($reference_id, $module, $username);
            $response = ['bank' => $bank];
            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(400);
            $error = ["error" => "reference_id is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    case 'POST':
        $logger->log("POST request received");
        $logger->log("Input: " . json_encode($input));

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is a required parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];

        if($bankOb->duplicateBankRecordCheck($reference_id)) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Bank account already exists for this vendor"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        if($input['transaction_type'] === 'Domestic') {
            $input['swift_code'] = null;
        } else if($input['transaction_type'] === 'International') {
            $input['ifsc_code'] = null;
        } else if($input['transaction_type'] === 'Domestic and International') {
            // Both codes required
            if (empty($input['ifsc_code']) || empty($input['swift_code'])) {
                http_response_code(400);
                $error = ["error" => "Both IFSC code and SWIFT code are required for 'Domestic and International' transaction type"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        } else {
            http_response_code(400);
            $error = ["error" => "Invalid transaction_type. Must be 'Domestic', 'International', or 'Domestic and International'"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        if(!isset($input['country_type'])) {
            http_response_code(400);
            $error = ["error" => "country_type is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        if($input['country_type'] === 'India'){
            $input['country_text'] = NULL;
            if(empty($input['country_id'])){
                http_response_code(400);
                $error = ["error" => "For country_type 'India', country_id is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        }

        if(isset($input['country_type']) && $input['country_type'] === 'Others'){
            $input['country_id'] = NULL;
            if(empty($input['country_text'])){
                http_response_code(400);
                $error = ["error" => "For country_type 'Others', country_text is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        }

        $result = $bankOb->insertBankAccount(
            $reference_id, 
            $input['account_holder_name'],
            $input['bank_name'],
            $input['bank_address'] ?? null,
            $input['transaction_type'],
            $input['country_type'] ?? null,
            $input['country_id'] ?? null,
            $input['country_text'] ?? null,
            $input['account_number'],
            $input['ifsc_code'] ?? null,
            $input['swift_code'] ?? null,
            $input['beneficiary_name'] ?? null,
            $module,
            $username
        );

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Bank account added successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to add bank account"];
        }
        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

        
            

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is a required parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];

        $bankInfo = $bankOb->getBankDetailsByReference($reference_id, $module, $username);

        if(!$bankInfo) {
            http_response_code(404);
            $error = ["error" => "Bank account not found for the provided reference_id"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        // if($bankOb->duplicateBankRecordCheck($reference_id)) {
        //     http_response_code(400);
        //     $error = ["error" => "Duplicate Record: Bank account already exists for this vendor"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse($input, $error);
        //     return;
        // }

        if($input['transaction_type'] === 'Domestic') {
            $input['swift_code'] = null;
        } else if($input['transaction_type'] === 'International') {
            $input['ifsc_code'] = null;
        } else if($input['transaction_type'] === 'Domestic and International') {
            // Both codes required
            if (empty($input['ifsc_code']) || empty($input['swift_code'])) {
                http_response_code(400);
                $error = ["error" => "Both IFSC code and SWIFT code are required for 'Domestic and International' transaction type"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        } else {
            http_response_code(400);
            $error = ["error" => "Invalid transaction_type. Must be 'Domestic', 'International', or 'Domestic and International'"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        if(!isset($input['country_type'])) {
            http_response_code(400);
            $error = ["error" => "country_type is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        if($input['country_type'] === 'India'){
            $input['country_text'] = NULL;
            if(empty($input['country_id'])){
                http_response_code(400);
                $error = ["error" => "For country_type 'India', country_id is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        }

        if(isset($input['country_type']) && $input['country_type'] === 'Others'){
            $input['country_id'] = NULL;
            if(empty($input['country_text'])){
                http_response_code(400);
                $error = ["error" => "For country_type 'Others', country_text is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        }

        $updateResult = $bankOb->updateBankAccount(
            $reference_id, 
            $input['account_holder_name'],
            $input['bank_name'],
            $input['bank_address'] ?? null,
            $input['transaction_type'],
            $input['country_type'] ?? null,
            $input['country_id'] ?? null,
            $input['country_text'] ?? null,
            $input['account_number'],
            $input['ifsc_code'] ?? null,
            $input['swift_code'] ?? null,
            $input['beneficiary_name'] ?? null,
            $module,
            $username
        );

        $response = $updateResult !== false
            ? ["message" => $updateResult>0 ? "Bank account updated successfully" : "No changes made to the bank account"]
            : ["error" => "Failed to update bank account"];

        http_response_code(isset($response['error']) ? 500 : 200);
        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;


        
    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Bank account ID is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $bank_id = intval($_GET['id']);
        $result = $bankOb->deleteBankAccount($bank_id, $module, $username);

        if ($result) {
            http_response_code(200);
            echo json_encode(["message" => "Bank account deleted successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to delete bank account"]);
        }
        $logger->logRequestAndResponse($_GET, $result);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method Not Allowed"]);
        break;
}
