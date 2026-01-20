<?php

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/Status.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';

authenticateJWT();  // Uncomment if you want to enforce JWT authentication

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

// CORS headers (adjust Trusted_Hosts or remove if not needed)


$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$statusObj = new Status();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT();
// $username = 'guest';
$module = 'Admin';

// Regex validation for code and status strings (only letters, digits, spaces, hyphens, underscores)
$regExpCode = '/^[a-zA-Z0-9\-_]+$/';
$regExpStatus = '/^[a-zA-Z0-9\s\-_]+$/';

switch ($method) {

    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['code']) && isset($_GET['module'])) {
            $code = trim($_GET['code']);
            $modVal = trim($_GET['module']);
            $data = $statusObj->getStatusByCodeAndModule($code, $modVal, $module, $username);
            $statusCode = $data ? 200 : 404;
            $response = $data ?: ["error" => "Status not found"];
            http_response_code($statusCode);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if(isset($_GET['module'])) {
            $modVal = trim($_GET['module']);
            $data = $statusObj->getStatusByModule($modVal, $module, $username);
            $statusCode = $data ? 200 : 404;
            $response = $data ?: ["error" => "Status not found"];
            http_response_code($statusCode);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }


        if (isset($_GET['code'])) {
            $code = trim($_GET['code']);
            $data = $statusObj->getStatusByCode($code, $module, $username);
            $statusCode = $data ? 200 : 404;
            $response = $data ?: ["error" => "Status not found"];
            http_response_code($statusCode);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        // combo request (optional fields parameter)
        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'code', 'status', 'module'];
            $fields = array_map('trim', $fields);
            $data = $statusObj->getStatusCombo($fields, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        // Pagination for GET all statuses
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $statusObj->getPaginatedStatuses($offset, $limit, $module, $username);
        $total = $statusObj->getStatusesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'statuses' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (empty($input['code']) || empty($input['status']) || empty($input['module'])) {
            http_response_code(400);
            $error = ["error" => "Code, Status, and Module are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $code = trim($input['code']);
        $stat = trim($input['status']);
        $modVal = trim($input['module']);

        if (!preg_match($regExpCode, $code)) {
            http_response_code(400);
            $error = ["error" => "Code must contain only letters, numbers, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($regExpStatus, $stat)) {
            http_response_code(400);
            $error = ["error" => "Status must contain only letters, numbers, spaces, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if ($statusObj->checkDuplicateStatus($code, $modVal)) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Status code and module combination already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $statusObj->insertStatus($code, $stat, $modVal, $module, $username);

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Status added successfully", "id" => (int)$result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add status"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (empty($input['code']) || empty($input['status']) || empty($input['module'])) {
            http_response_code(400);
            $error = ["error" => "Code, Status, and Module are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $code = trim($input['code']);
        $stat = trim($input['status']);
        $modVal = trim($input['module']);

        if (!preg_match($regExpCode, $code)) {
            http_response_code(400);
            $error = ["error" => "Code must contain only letters, numbers, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($regExpStatus, $stat)) {
            http_response_code(400);
            $error = ["error" => "Status must contain only letters, numbers, spaces, hyphens or underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // Duplicate check should exclude current code+module, so you may want to modify checkDuplicateStatus method accordingly
        // For now, let's allow update regardless (or add a new method if needed)

        $result = $statusObj->updateStatus($code, $stat, $modVal, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Status updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update status"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (empty($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "id is required for deletion"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $code = trim($_GET['id']);

        if (!preg_match($regExpCode, $code)) {
            http_response_code(400);
            $error = ["error" => "id must be only integers"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $result = $statusObj->deleteStatus($code, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Status deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete status"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse(['method' => $method], $error);
        break;
}
