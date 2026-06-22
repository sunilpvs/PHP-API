<?php 
// endpoint for deleting an existing leave type.
// /api/lms/leave-type/delete.php
// only for deleting leave types

// allow only DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Only DELETE requests are allowed.']);
    exit;
}

// include necessary files and initialize classes
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/lms/LeaveTypes.php';
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
// $lettersAndDigitsWithSpacesRegExp = '/^[a-zA-Z0-9\s]+$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$leaveTypeOb = new LeaveTypes();
$auth = new UserLogin();
// $token = $auth->getMicrosoftAccessToken();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/leave-type/delete.php");

    // TODO: only allow users with HR_ADMIN role to delete leave types.
    

    // get id from query parameters
    if (!isset($_GET['id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required field. Please provide the id of the leave type to delete.']);
        exit;
    }
    $id = $_GET['id'];

    // validate that the leave type with the given id exists
    $existingLeaveType = $leaveTypeOb->getLeaveTypeById($id, $module, $username);
    if (!$existingLeaveType) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Leave type not found. Please provide a valid id.']);
        exit;
    }
    

    $leaveTypeOb->deleteLeaveType($id, $module, $username);

    http_response_code(200); // OK
    echo json_encode(['message' => 'Leave type deleted successfully.']);

} catch (Exception $e) {
    $logger->log("Error in /api/lms/leave-type/delete.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while deleting the leave type.']);  
}
