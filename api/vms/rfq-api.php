<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Rfq.php';

// Authenticate using JWT
authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$rfqOb = new Rfq();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'RFQ';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {

    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $rfqOb->getRfqById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "RFQ not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] == 'pending-rfqs') {
            $data = $rfqOb->getPendingSubmittedRfqs($module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] == 'all-rfqs') {
            $data = $rfqOb->getAllSubmittedRfqs($module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] == 'all-vendors') {
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
            $offset = ($page - 1) * $limit;
            $data = $rfqOb->getAllVendors($offset, $limit, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] == 'vendor-rfqs') {
            $vendor_code = $_GET['vendor_code'] ?? '';
            if (empty($vendor_code)) {
                http_response_code(400);
                $error = ["error" => "vendor_code is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error);
                break;
            }
            
            $data = $rfqOb->getAllRfqsByVendor($vendor_code, $module, $username);
            http_response_code(200);
            echo json_encode(['vendor-rfqs' => $data]);
            $logger->logRequestAndResponse($_GET, ['vendor-rfqs' => $data]);
            break;
        }

        // rfqs for vendor from jwt - vendor portal to view their rfqs 
        if(isset($_GET['type']) && $_GET['type'] == 'vendor-user-rfqs') {
            $data = $rfqOb->getAllRfqsByUserId($username, $module, $username);
            http_response_code(200);
            echo json_encode(['rfqs' => $data]);
            $logger->logRequestAndResponse($_GET, ['rfqs' => $data]);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] == 'submitted-rfq-count') {
            $submittedCount = $rfqOb->getSubmittedRfqsCount($module, $username);
            http_response_code(200);
            $response = ['submitted_count' => $submittedCount];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] == 'active-vendor-count') {
            $activeVendorCount = $rfqOb->getActiveVendorsCount($module, $username);
            http_response_code(200);
            $response = ['active_vendor_count' => $activeVendorCount];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }


        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $rfqOb->getPaginatedRfqs($offset, $limit, $module, $username);
        $total = $rfqOb->getRfqsCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rfqs' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        $required = ['vendor_name', 'contact_name', 'email', 'mobile', 'entity_id',];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                $error = ["error" => ucfirst(str_replace('_', ' ', $field)) . " is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        }

        if ($rfqOb->checkDuplicateRfq($input['vendor_name'], $input['email'], $input['mobile'])) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: RFQ already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        $insertResult = $rfqOb->insertRfq(
            $input['vendor_name'],
            $input['contact_name'],
            $input['email'],
            $input['mobile'],
            $input['entity_id'],
            $username,
            $module,
            $username
        );


        if ($insertResult) {
            http_response_code(201);
            $response = ["message" => "RFQ added successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to add RFQ"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;


    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "RFQ ID is required and must be a number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $deleteResult = $rfqOb->deleteRfq($id, $module, $username);

        if ($deleteResult > 0) {
            http_response_code(200);
            $response = ["message" => "RFQ deleted successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to delete RFQ"];
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
