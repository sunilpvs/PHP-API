<?php
// endpoint for creating a modification request for an existing approved leave.
// /api/lms/modification/create.php

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

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$leaveReqOb = new LeaveRequests();
$auth = new UserLogin();
$token = $auth->getMicrosoftAccessToken();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/modification/create.php");

    // required fields: rootRequestId, startDate, endDate, adjustmentReason
    if (!isset($input['rootRequestId'], $input['startDate'], $input['endDate'], $input['adjustmentReason'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields. Provide rootRequestId, startDate, endDate, adjustmentReason.']);
        exit;
    }

    if (!is_numeric($input['rootRequestId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid rootRequestId. It should be numeric.']);
        exit;
    }

    // check if the user is the owner of the leave request or their manager before allowing them to create a modification request
    // check if there is an approved leave request with the given rootRequestId 

    $leaveRequest = $leaveReqOb->getLeaveRequestById((int)$input['rootRequestId'], $module, $username);
    if (!$leaveRequest || $leaveRequest['status'] !== 17 || ($leaveRequest['emailOfEmployee'] !== $email)) {
        http_response_code(400);
        echo json_encode(['error' => 'No approved leave request found with the given rootRequestId.']);
        exit;
    }

    // validate startDate and endDate
    if (!DateTime::createFromFormat('Y-m-d', $input['startDate']) || !DateTime::createFromFormat('Y-m-d', $input['endDate'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. startDate and endDate should be in YYYY-MM-DD format.']);
        exit;
    }

    $startDate = DateTime::createFromFormat('Y-m-d', $input['startDate']);
    $endDate = DateTime::createFromFormat('Y-m-d', $input['endDate']);

    if ($endDate < $startDate) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date range. endDate cannot be before startDate.']);
        exit;
    }

    $rootRequestId = (int)$input['rootRequestId'];
    $adjustmentReason = trim((string)$input['adjustmentReason']);

    $result = $leaveReqOb->createModificationRequest(
        $token,
        $rootRequestId,
        $startDate,
        $endDate,
        $adjustmentReason,
        $username,
        $module,
        $username
    );

    if ($result) {
        http_response_code(201);
        echo json_encode(['message' => 'Modification request created successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to create modification request. Ensure the root request exists and is approved.']);
    }
} catch (Exception $e) {
    $logger->log("Error processing request: " . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred. Please try again later.']);
}
