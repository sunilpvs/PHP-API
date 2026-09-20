<?php

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/M365Admins.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$m365AdminsOb = new M365Admins();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log('GET request received');

        if (isset($_GET['id'])) {
            if (!is_numeric($_GET['id']) || (int) $_GET['id'] <= 0) {
                http_response_code(400);
                $response = ['error' => 'M365 admin ID must be a valid positive number'];
                echo json_encode($response);
                $logger->logRequestAndResponse($_GET, $response);
                break;
            }

            $data = $m365AdminsOb->getM365AdminById((int) $_GET['id'], $module, $username);
            $statusCode = $data ? 200 : 404;
            $response = $data ?: ['error' => 'M365 admin not found'];
            http_response_code($statusCode);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['status'])) {
            $status = strtolower(trim($_GET['status']));
            if ($status === 'active') {
                $response = $m365AdminsOb->getActiveM365Admins($module, $username);
            } elseif ($status === 'inactive' || $status === 'in-active') {
                $response = $m365AdminsOb->getInActiveM365Admins($module, $username);
            } else {
                http_response_code(400);
                $response = ['error' => 'Status must be active or inactive'];
                echo json_encode($response);
                $logger->logRequestAndResponse($_GET, $response);
                break;
            }

            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;
        $data = $m365AdminsOb->getPaginatedM365Admins($offset, $limit, $module, $username);
        $total = $m365AdminsOb->getM365AdminsCount($module, $username);
        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'm365_admins' => $data,
        ];
        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log('POST request received');

        if (!is_array($input) || !isset($input['employee_id']) || !is_numeric($input['employee_id']) || (int) $input['employee_id'] <= 0) {
            http_response_code(400);
            $response = ['error' => 'Employee ID must be a valid positive number'];
            echo json_encode($response);
            $logger->logRequestAndResponse($input ?: [], $response);
            break;
        }

        if (!isset($input['status']) || !in_array((string) $input['status'], ['1', '2'], true)) {
            http_response_code(400);
            $response = ['error' => 'Status must be either 1 (Active) or 2 (In-Active)'];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
            break;
        }

        $employeeId = (int) $input['employee_id'];
        $status = (int) $input['status'];
        if ($m365AdminsOb->checkDuplicateM365Admin($employeeId)) {
            http_response_code(400);
            $response = ['error' => 'Duplicate Record: M365 admin already exists for this employee'];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
            break;
        }

        try {
            $result = $m365AdminsOb->addM365Admin($employeeId, $status, $username, $module, $username);
            if ($result) {
                http_response_code(201);
                $response = ['message' => 'M365 admin added successfully', 'id' => (int) $result];
            } else {
                http_response_code(500);
                $response = ['error' => 'Failed to add M365 admin'];
            }
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $response = ['error' => $exception->getMessage()];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

    case 'PUT':
        $logger->log('PUT request received');

        if (!isset($_GET['id']) || !is_numeric($_GET['id']) || (int) $_GET['id'] <= 0) {
            http_response_code(400);
            $response = ['error' => 'M365 admin ID must be a valid positive number'];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input ?: []), $response);
            break;
        }

        if (!is_array($input) || !isset($input['employee_id']) || !is_numeric($input['employee_id']) || (int) $input['employee_id'] <= 0) {
            http_response_code(400);
            $response = ['error' => 'Employee ID must be a valid positive number'];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input ?: []), $response);
            break;
        }

        if (!isset($input['status']) || !in_array((string) $input['status'], ['1', '2'], true)) {
            http_response_code(400);
            $response = ['error' => 'Status must be either 1 (Active) or 2 (In-Active)'];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
            break;
        }

        $id = (int) $_GET['id'];
        $employeeId = (int) $input['employee_id'];
        $status = (int) $input['status'];
        if ($m365AdminsOb->checkEditDuplicateM365Admin($employeeId, $id)) {
            http_response_code(400);
            $response = ['error' => 'Duplicate Record: M365 admin already exists for this employee'];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
            break;
        }

        $result = $m365AdminsOb->updateM365Admin($employeeId, $status, $username, $id, $module, $username);
        if ($result !== false) {
            http_response_code(200);
            $response = ['message' => $result > 0 ? 'M365 admin updated successfully' : 'No changes made'];
        } else {
            http_response_code(500);
            $response = ['error' => 'Failed to update M365 admin'];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    default:
        http_response_code(405);
        $response = ['error' => 'Method not allowed'];
        echo json_encode($response);
        $logger->logRequestAndResponse(['method' => $method], $response);
        break;
}
?>