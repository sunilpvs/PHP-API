<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}
require_once __DIR__ . '../../../classes/admin/UserRole.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

//Validate login and authenticate JWT
authenticateJWT();

//Reading app.ini configuration file
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
//Front End authorization as Trusted Hosts.

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$userObj = new UserRole();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':

        // assuming email is obtained from the JWT token 
        $email = $username;

        if (!$email) {
            http_response_code(400);
            echo json_encode(['error' => 'No email found for the user']);
            exit;
        }

        $type = $_GET['type'] ?? null;

        if (!isset($type)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing type parameter.']);
            exit;
        }

        if ($type === 'vms') {
            $result = $userObj->getVmsUserRoleByEmail($email, $module, $username);
            if ($result === null) {
                http_response_code(404);
                echo json_encode(['error' => 'User role not found for the provided email']);
                exit;
            }

            http_response_code(200);
            echo json_encode($result);
            break;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type parameter']);
            exit;
        }



    default:
        http_response_code(405);
        echo json_encode(['error' => 'Invalid request method']);
        break;
}
