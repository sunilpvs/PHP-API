<?php 
// endpoint for deleting a leave policy.
// /api/lms/leave-policy/delete.php
// only for deleting leave policies

// allow only DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Only DELETE requests are allowed.']);
    exit;
}

// include necessary files and initialize classes
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/lms/LeavePolicy.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

// Validate login and authenticate JWT
authenticateJWT();

// read app.ini configuration file
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);


$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$leavePolicyOb = new LeavePolicy();
$auth = new UserLogin();
// $token = $auth->getMicrosoftAccessToken();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/leave-policy/delete.php");

    // TODO: only allow users with HR_ADMIN role to delete leave policies.
    
    if (isset($_GET['id'])) {
        $leavePolicyId = $_GET['id'];
    } else {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required field. Please provide leave policy id in the query parameter.']);
        exit;
    }

    // check the existence of the leave policy with the given id
    $existingLeavePolicy = $leavePolicyOb->getLeavePolicyById($leavePolicyId, $module, $username);
    if (!$existingLeavePolicy) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Leave policy not found.']);
        exit;
    }


    $leavePolicyOb->deleteLeavePolicy($leavePolicyId, $module, $username);

    http_response_code(200); // OK
    echo json_encode(['message' => 'Leave policy deleted successfully.']);

} catch (Exception $e) {
    $logger->log("Error in /api/lms/leave-policy/delete.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while deleting the leave policy.']);  
}
