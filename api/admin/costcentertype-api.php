<?php


if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/admin/Costcentertype.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

authenticateJWT(); // Enable this if JWT auth is needed

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$regExp = '/^[a-zA-Z\s]+$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$ccTypeOb = new CostCenterType();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $ccTypeOb->getCostCenterTypeById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Cost Center Type not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] === 'count') {
            $data = $ccTypeOb->getCostCenterTypesCount($module, $username);
            http_response_code(200);
            echo json_encode(['count' => $data]);
            $logger->logRequestAndResponse($_GET, ['count' => $data]);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $ccTypeOb->getPaginatedCostCenterTypes($offset, $limit, $module, $username);
        $total = $ccTypeOb->getCostCenterTypesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'costcentertypes' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($input['cc_type']) || empty(trim($input['cc_type']))) {
            http_response_code(400);
            $error = ["error" => "Cost Center Type name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $cc_type = trim($input['cc_type']);

        if (!preg_match($regExp, $cc_type)) {
            http_response_code(400);
            $error = ["error" => "Cost Center Type name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $duplicate = $ccTypeOb->getCostCenterTypeByName($cc_type, $module, $username);
        if ($duplicate) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Cost Center Type already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $ccTypeOb->insertCostCenterType($cc_type, $module, $username);
        if ($result) {
            http_response_code(201);
            $response = ["message" => "Cost Center Type added successfully", "id" => (int)$result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add Cost Center Type"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Valid Cost Center Type ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        if (!isset($input['cc_type']) || empty(trim($input['cc_type']))) {
            http_response_code(400);
            $error = ["error" => "Cost Center Type name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $id = intval($_GET['id']);
        $cc_type = trim($input['cc_type']);

        if (!preg_match($regExp, $cc_type)) {
            http_response_code(400);
            $error = ["error" => "Cost Center Type name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $duplicate = $ccTypeOb->getCostCenterTypeByName($cc_type, $module, $username);
        if ($duplicate && intval($duplicate['id']) !== $id) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Another Cost Center Type with this name exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $ccTypeOb->updateCostCenterType($id, $cc_type, $module, $username);
        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Cost Center Type updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update Cost Center Type"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Cost Center Type ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $ccTypeOb->deleteCostCenterType($id, $module, $username);
        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Cost Center Type deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete Cost Center Type"];
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
?>
