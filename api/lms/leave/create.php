<?php
// endpoint for creating a new leave request. This will be called when an employee applies for a new leave.
// /api/lms/leave/create.php
// only for creating new leave requests. Modifications to existing leave requests will be handled by a separate endpoint.

// only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Only POST requests are allowed.']);
    exit;
}

// include necessary files and initialize classes
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/lms/LeaveRequests.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/Logger.php';

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

$leaveReqOb = new LeaveRequests();
$auth = new UserLogin();
$token = $auth->getMicrosoftAccessToken();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/leave/create.php");

    // validate input
    // public function createNewLeaveRequest($authToken, $leaveTitle, $emailOfEmployee, $startDate, $endDate, $leaveType, $reason, $createdBy, $module, $username)
    if (!isset($input['leaveTitle'], $input['emailOfEmployee'], $input['startDate'], $input['endDate'], $input['leaveType'], $input['reason'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required fields. Please provide leaveTitle, emailOfEmployee, startDate, endDate, leaveType, and reason.']);
        exit;
    }

    if (!preg_match($lettersAndDigitsWithSpacesRegExp, $input['leaveTitle'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid leaveTitle. Only letters and spaces are allowed.']);
        exit;
    }

    $leaveTitle = $input['leaveTitle'];

    // validate startDate and endDate

    if (!DateTime::createFromFormat('Y-m-d', $input['startDate']) || !DateTime::createFromFormat('Y-m-d', $input['endDate'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid date format. startDate and endDate should be in YYYY-MM-DD format.']);
        exit;
    }

    $startDate = DateTime::createFromFormat('Y-m-d', $input['startDate']);
    $endDate = DateTime::createFromFormat('Y-m-d', $input['endDate']);

    if ($endDate < $startDate) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid date range. endDate cannot be before startDate.']);
        exit;
    }

    // validate leaveType
    if (!is_numeric($input['leaveType'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid leaveType. leaveType should be a numeric ID corresponding to a valid leave type.']);
        exit;
    }

    // validate reason
    if (!preg_match($lettersAndDigitsWithSpacesRegExp, $input['reason'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid reason. Only letters and spaces are allowed.']);
        exit;
    }

    $leaveType = (int)$input['leaveType'];
    $reason = $input['reason'];

    $result = $leaveReqOb->createNewLeaveRequest(
        $token,
        $leaveTitle,
        $email,
        $startDate,
        $endDate,
        $leaveType,
        $reason,
        $username,
        $module,
        $username
    );

    if ($result) {
        http_response_code(201); // Created
        echo json_encode(['message' => 'Leave request created successfully.']);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Failed to create leave request. Please try again later.']);
    }
} catch (Exception $e) {
    $logger->log("Error processing request: " . $e->getMessage(), 'error');
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An unexpected error occurred. Please try again later.']);
}
