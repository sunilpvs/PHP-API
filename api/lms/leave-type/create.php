<?php 
// endpoint for creating a new leave type.
// /api/lms/leave-type/create.php
// only for creating leave types

// allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Only POST requests are allowed.']);
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
$lettersAndDigitsWithSpacesRegExp = '/^[a-zA-Z0-9\s]+$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$leaveTypeOb = new LeaveTypes();
$auth = new UserLogin();
// $token = $auth->getMicrosoftAccessToken();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/leave-type/create.php");

    // TODO: only allow users with HR_ADMIN role to create leave types.
    

    // validate input
    if (!isset($input['leave_type_name'], $input['description'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required fields. Please provide leaveTypeName and description.']);
        exit;
    }

    if (!preg_match($lettersAndDigitsWithSpacesRegExp, $input['leave_type_name'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid leaveTypeName. Only letters, digits, and spaces are allowed.']);
        exit;
    }

    if (!preg_match($lettersAndDigitsWithSpacesRegExp, $input['description'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid description. Only letters, digits, and spaces are allowed.']);
        exit;
    }

    $leaveTypeName = $input['leave_type_name'];
    $description = $input['description'];

    $leaveTypeOb->createLeaveType($leaveTypeName, $description, $username, $module, $username);

    http_response_code(201); // Created
    echo json_encode(['message' => 'Leave type created successfully.']);

} catch (Exception $e) {
    $logger->log("Error in /api/lms/leave-type/create.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while creating the leave type.']);  
}
