<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/logs/ActivityLog.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

// Validate login and authenticate JWT
authenticateJWT();

// Load configuration
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$logOb = new ActivityLog();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received for activity logs");

        // Extract query parameters
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $filterModule = isset($_GET['module']) ? trim($_GET['module']) : null;
        $filterUser = isset($_GET['username']) ? trim($_GET['username']) : null;
        $fromDate = isset($_GET['fromDate']) ? trim($_GET['fromDate']) : null;
        $toDate = isset($_GET['toDate']) ? trim($_GET['toDate']) : null;

        $type = isset($_GET['type']) ? trim($_GET['type']) : null;

        // type is required
        if($type === null) {
            http_response_code(400);
            echo json_encode(["error" => "Type parameter is required"]);
            exit;
        }

        if($type === 'count') {
            $count = $logOb->getActivityLogCount($filterModule, $filterUser, $fromDate, $toDate, $module, $username);
            http_response_code(200);
            echo json_encode(['count' => $count]);
            $logger->logRequestAndResponse($_GET, ['count' => $count]);
            break;
        }

        // Fetch logs for vendor from jwt 
        if($type === 'vendor'){
            $data = $logOb->getVendorActivityLogs($username, $page, $limit, $filterUser, $fromDate, $toDate, $module, $username);
            http_response_code(200);
            echo json_encode(['logs' => $data]);
            $logger->logRequestAndResponse($_GET, ['logs' => $data]);
            break;
        }

        if($type === 'vms'){
            $data = $logOb->getVmsActivityLogs($page, $limit, $filterUser, $fromDate, $toDate, $module, $username);
            http_response_code(200);
            echo json_encode(['logs' => $data]);
            $logger->logRequestAndResponse($_GET, ['logs' => $data]);
            break;
        }

        if($type === 'admin'){
            $data = $logOb->getAdminActivityLogs($page, $limit, $filterUser, $fromDate, $toDate, $module, $username);
            http_response_code(200);
            echo json_encode(['logs' => $data]);
            $logger->logRequestAndResponse($_GET, ['logs' => $data]);
            break;
        }


    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
?>
