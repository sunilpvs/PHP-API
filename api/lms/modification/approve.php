<?php
// endpoint for approving a modification request. This will be called by the approver for modification requests only.
// /api/lms/modification/approve.php

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
    $logger->log("Received $method request at /api/lms/modification/approve.php");

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

    // check if the request with the given id exists and is a pending modification request
    $modificationRequest = $leaveReqOb->getLeaveRequestById((int)$input['id'], $module, $username);
    if (!$modificationRequest || $modificationRequest['status'] !== 18) {
        http_response_code(400);
        echo json_encode(['error' => 'No pending modification request found with the given id.']);
        exit;
    }

    // check if there is an approved and active leave request (parent request) associated with the modification request
    $parentRequest = $leaveReqOb->getLeaveRequestById((int)$modificationRequest['parent_request_id'], $module, $username);
    if (!$parentRequest || $parentRequest['status'] !== 17 || $parentRequest['is_active'] !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'No approved and active parent leave request found for this modification request.']);
        exit;
    }

    $id = (int)$input['id'];
    $adminComments = isset($input['adminComments']) ? trim((string)$input['adminComments']) : '';

    $result = $leaveReqOb->approveModificationRequest(
        $id,
        $adminComments,
        $updatedBy,
        $module,
        $username
    );

    if ($result) {
        http_response_code(200);
        echo json_encode(['message' => 'Modification request approved successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to approve modification request. Please ensure it is a pending modification request.']);
    }
} catch (Exception $e) {
    $logger->log("Error processing request: " . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred. Please try again later.']);
}
