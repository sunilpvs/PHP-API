<?php
// endpoint for rejecting a leave request. This will be called by the approver for new leave requests only.
// /api/lms/leave/reject.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only POST requests are allowed.']);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/lms/LeaveRequests.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/Logger.php';

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$leaveReqOb = new LeaveRequests();
$auth = new UserLogin();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$updatedBy = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$module = 'Leave Management System';

try {
    $logger->log("Received $method request at /api/lms/leave/reject.php");

    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field. Please provide id.']);
        exit;
    }

    if (!is_numeric($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid id. id should be a numeric leave request ID.']);
        exit;
    }

    $id = (int)$input['id'];
    $adminComments = isset($input['adminComments']) ? trim((string)$input['adminComments']) : '';

    $result = $leaveReqOb->rejectLeaveRequest(
        $id,
        $adminComments,
        $updatedBy,
        $module,
        $username
    );

    if ($result) {
        http_response_code(200);
        echo json_encode(['message' => 'Leave request rejected successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to reject leave request. Please ensure it is a pending new leave request.']);
    }
} catch (Exception $e) {
    $logger->log("Error processing request: " . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred. Please try again later.']);
}