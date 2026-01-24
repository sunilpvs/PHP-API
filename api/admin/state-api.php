<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/State.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';

authenticateJWT(); // Enable JWT authentication

// Load config and setup logger
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

// Setup request and context
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$stateObj = new State();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT();
// $username = 'guest';
$module = 'Admin';

$regExp = '/^[a-zA-Z\s]+$/';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $stateObj->getStateById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "State not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['country'])) {
            $countryId = intval($_GET['country']);
            $data = $stateObj->getStatesByCountry($countryId, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'state'];
            $fields = array_map('trim', $fields);
            $data = $stateObj->getStateCombo($fields, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $stateObj->getPaginatedStates($offset, $limit, $module, $username);
        $total = $stateObj->getStatesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'states' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (
            !isset($input['state'], $input['country']) ||
            empty(trim($input['state'])) ||
            !is_numeric($input['country'])
        ) {
            http_response_code(400);
            $error = ["error" => "State name and valid country ID are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $stateName = trim($input['state']);
        $countryId = intval($input['country']);

        if (!preg_match($regExp, $stateName)) {
            http_response_code(400);
            $error = ["error" => "State name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if ($stateObj->checkDuplicateState($stateName, $countryId)) {
            http_response_code(400);
            $error = ["error" => "Duplicate state for this country"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $stateObj->insertState($stateName, $countryId, $module, $username);

        if ($result) {
            http_response_code(201);
            $response = ["message" => "State added successfully", "id" => (int)$result];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to add state"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (
            !isset($_GET['id']) ||
            !isset($input['state'], $input['country']) ||
            empty(trim($input['state'])) ||
            !is_numeric($input['country'])
        ) {
            http_response_code(400);
            $error = ["error" => "ID, state name, and valid country ID are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $id = intval($_GET['id']);
        $stateName = trim($input['state']);
        $countryId = intval($input['country']);

        if (!preg_match($regExp, $stateName)) {
            http_response_code(400);
            $error = ["error" => "State name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if ($stateObj->checkDuplicateState($stateName, $countryId)) {
            http_response_code(400);
            $error = ["error" => "Duplicate state for this country"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $stateObj->updateState($stateName, $countryId, $id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "State updated successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to update state"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "ID is required for deletion"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $stateObj->deleteState($id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "State deleted successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to delete state"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
