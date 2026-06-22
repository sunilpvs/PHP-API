<?php 

// This file is for fetching leave types
// /api/lms/leave-type/get.php
// for fetching leave types from the database and returning them as a JSON response


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only GET requests are allowed.']);
    exit;
}

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

$method = $_SERVER['REQUEST_METHOD'];
$leaveTypeOb = new LeaveTypes();
$auth = new UserLogin();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
// $token = $auth->getMicrosoftAccessToken();
$module = 'Leave Management System';

try {
    $logger->log("Recieved $method reqeust at /api/lms/leave-type/get.php", [], 'api', $module, $username);

    if (isset($_GET['type']) && $_GET['type'] === 'combo') {
        $leaveTypes = $leaveTypeOb->getLeaveTypeCombo($module, $username);
        http_response_code(200);
        echo json_encode(['data' => $leaveTypes]);
        exit;
    }

    if (isset($_GET['id'])) {
        $leaveTypeId = $_GET['id'];
        $leaveType = $leaveTypeOb->getLeaveTypeById($leaveTypeId, $module, $username);
        if ($leaveType) {
            http_response_code(200);
            echo json_encode(['data' => $leaveType]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Leave type not found']);
        }
        exit;
    }

    // get all leave types 
    $leaveTypes = $leaveTypeOb->getAllLeaveTypes($module, $username);

    // return the leave types as a JSON response
    http_response_code(200);
    echo json_encode(['data' => $leaveTypes]);

} catch (Exception $e) {
    $logger->log("Error processing request: " . $e->getMessage(), [], 'api', $module, $username);
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while processing your request.']);
}
