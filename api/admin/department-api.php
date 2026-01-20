<?php

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/admin/Department.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$regExp = '/^[a-zA-Z\s]+$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$departmentOb = new Department();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $departmentOb->getDepartmentById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Department not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['code'])) {
            $code = trim($_GET['code']);
            $data = $departmentOb->getDepartmentByCode($code, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Department not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        // Pagination parameters
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $departmentOb->getPaginatedDepartments($offset, $limit, $module, $username);
        $total = $departmentOb->getDepartmentsCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'departments' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($input['name']) || empty(trim($input['name']))) {
            http_response_code(400);
            $error = ["error" => "Department name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['code']) || empty(trim($input['code']))) {
            http_response_code(400);
            $error = ["error" => "Department code is required"];
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

        // Validate name and code - only alphabets and spaces for name, code can be alphanumeric + underscore
        if (!preg_match($regExp, $name)) {
            http_response_code(400);
            $error = ["error" => "Department name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $code)) {
            http_response_code(400);
            $error = ["error" => "Department code must contain only alphanumeric characters and underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // Duplicate check (optional, implement if you have such method)
        // $duplicate = $departmentOb->checkDuplicateDepartment($name, $code);
        // if ($duplicate) {
        //     http_response_code(400);
        //     $error = ["error" => "Duplicate Record: Department already exists"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse($input, $error);
        //     break;
        // }

        $result = $departmentOb->insertDepartment($name, $code, $status, $module, $username);

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Department added successfully", "id" => (int)$result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add department"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Department ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($input['name']) || empty(trim($input['name']))) {
            http_response_code(400);
            $error = ["error" => "Department name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($input['code']) || empty(trim($input['code']))) {
            http_response_code(400);
            $error = ["error" => "Department code is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($input['status']) || !is_numeric($input['status'])) {
            http_response_code(400);
            $error = ["error" => "Valid status is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $id = intval($_GET['id']);
        $name = trim($input['name']);
        $code = trim($input['code']);
        $status = intval($input['status']);

        if (!preg_match($regExp, $name)) {
            http_response_code(400);
            $error = ["error" => "Department name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $code)) {
            http_response_code(400);
            $error = ["error" => "Department code must contain only alphanumeric characters and underscores"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        // Duplicate check (optional)
        // $duplicate = $departmentOb->checkDuplicateDepartment($name, $code);
        // if ($duplicate) {
        //     http_response_code(400);
        //     $error = ["error" => "Duplicate Record: Department already exists"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse($input, $error);
        //     break;
        // }

        $result = $departmentOb->updateDepartment($id, $name, $code, $status, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Department updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update department"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Department ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $departmentOb->deleteDepartment($id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Department deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete department"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
