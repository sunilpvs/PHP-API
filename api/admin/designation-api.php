<?php

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/admin/Designation.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

// Validate login and authenticate JWT
authenticateJWT();

// Reading app.ini configuration file
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

// Regular expression for validation: only letters, digits, spaces, hyphens and underscores allowed
$regExpName = '/^[a-zA-Z0-9\s\-_]+$/';
$regExpCode = '/^[a-zA-Z0-9\-_]+$/';

// Front End authorization as Trusted Hosts (optional)
// You can add CORS headers here if needed

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$designationOb = new Designation();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $designationOb->getDesignationById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Designation not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['code'])) {
            $code = trim($_GET['code']);
            $data = $designationOb->getDesignationByCode($code, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Designation not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'name', 'code'];
            $fields = array_map('trim', $fields);
            $data = $designationOb->getDesignationCombo($fields, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $designationOb->getPaginatedDesignations($offset, $limit, $module, $username);
        $total = $designationOb->getDesignationsCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'designations' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($input['name']) || empty(trim($input['name']))) {
            http_response_code(400);
            $error = ["error" => "Designation name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['code']) || empty(trim($input['code']))) {
            http_response_code(400);
            $error = ["error" => "Designation code is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['status']) || !is_numeric($input['status'])) {
            http_response_code(400);
            $error = ["error" => "Valid status is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $name = trim($input['name']);
        $code = trim($input['code']);
        $status = intval($input['status']);

        if (!preg_match($regExpName, $name)) {
            http_response_code(400);
            $error = ["error" => "Designation name must contain only alphabets, numbers, spaces, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($regExpCode, $code)) {
            http_response_code(400);
            $error = ["error" => "Designation code must contain only alphabets, numbers, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $duplicate = $designationOb->checkDuplicateDesignation($name, $code);
        if ($duplicate) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Designation name or code already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $designationOb->insertDesignation($name, $code, $status, $module, $username);

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Designation added successfully", "id" => (int)$result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add designation"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Designation ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input ?: []), $error);
            break;
        }

        if (!is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Designation ID must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        if (!isset($input['name']) || empty(trim($input['name']))) {
            http_response_code(400);
            $error = ["error" => "Designation name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['code']) || empty(trim($input['code']))) {
            http_response_code(400);
            $error = ["error" => "Designation code is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['status']) || !is_numeric($input['status'])) {
            http_response_code(400);
            $error = ["error" => "Valid status is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $id = intval($_GET['id']);
        $name = trim($input['name']);
        $code = trim($input['code']);
        $status = intval($input['status']);

        if (!preg_match($regExpName, $name)) {
            http_response_code(400);
            $error = ["error" => "Designation name must contain only alphabets, numbers, spaces, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!preg_match($regExpCode, $code)) {
            http_response_code(400);
            $error = ["error" => "Designation code must contain only alphabets, numbers, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        


        $result = $designationOb->updateDesignation($name, $code, $status, $id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Designation updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update designation"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Designation ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $designationOb->deleteDesignation($id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Designation deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete designation"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method Not Allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse(['method' => $method], $error);
        break;
}
