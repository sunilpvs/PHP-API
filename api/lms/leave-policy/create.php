<?php 
// endpoint for creating a new leave policy.
// /api/lms/leave-policy/create.php
// only for creating leave policies

// allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Only POST requests are allowed.']);
    exit;
}

// include necessary files and initialize classes
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/lms/LeavePolicy.php';
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

$leavePolicyOb = new LeavePolicy();
$leaveTypeOb = new LeaveTypes();
$auth = new UserLogin();
// $token = $auth->getMicrosoftAccessToken();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/leave-policy/create.php");

    // TODO: only allow users with HR_ADMIN role to create leave policies.
    

    // validate input
    if (!isset($input['leave_type_id'], $input['annual_quota'], $input['year'], $input['carry_forward'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required fields. Please provide leave_type_id, annual_quota, year, and carry_forward.']);
        exit;
    }

    if(!is_numeric($input['leave_type_id']) || !is_numeric($input['annual_quota']) || !is_numeric($input['year']) || !is_bool($input['carry_forward'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid input types. leave_type_id, annual_quota, and year must be numeric, and carry_forward must be boolean.']);
        exit;
    }

    $leaveTypeId = $input['leave_type_id'];
    $annualQuota = $input['annual_quota'];
    $year = $input['year'];
    $carryForward = $input['carry_forward'];

    // check if a leave type exists with the given leave_type_id
    $existingLeaveType = $leaveTypeOb->getLeaveTypeById($leaveTypeId, $module, $username);
    if (!$existingLeaveType) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Leave type not found. Please provide a valid leave_type_id.']);
        exit;
    }

    $leavePolicyOb->createLeavePolicy($leaveTypeId, $annualQuota, $year, $carryForward, $username, $module, $username);

    http_response_code(201); // Created
    echo json_encode(['message' => 'Leave policy created successfully.']);

} catch (Exception $e) {
    $logger->log("Error in /api/lms/leave-policy/create.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while creating the leave policy.']);  
}
