<?php 

// This file is for fetching leave policies
// /api/lms/leave-policy/get.php
// for fetching leave policies from the database and returning them as a JSON response


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only GET requests are allowed.']);
    exit;
}

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
$leavePolicyOb = new LeavePolicy();
$auth = new UserLogin();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
// $token = $auth->getMicrosoftAccessToken();
$module = 'Leave Management System';

try {
    $logger->log("Recieved $method reqeust at /api/lms/leave-policy/get.php", [], 'api', $module, $username);


    if (isset($_GET['id'])) {
        $leavePolicyId = $_GET['id'];
        $leavePolicy = $leavePolicyOb->getLeavePolicyById($leavePolicyId, $module, $username);
        if ($leavePolicy) {
            http_response_code(200);
            echo json_encode(['data' => $leavePolicy]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Leave policy not found']);
        }
        exit;
    }

    if (isset($_GET['year'])) {
        $year = $_GET['year'];
        $leavePoliciesByYear = $leavePolicyOb->getLeavePoliciesByYear($year, $module, $username);
        http_response_code(200);
        echo json_encode(['data' => $leavePoliciesByYear]);
        exit;
    }

    // get all leave policies
    $leavePolicies = $leavePolicyOb->getAllLeavePolicies($module, $username);

    // return the leave policies as a JSON response
    http_response_code(200);
    echo json_encode(['data' => $leavePolicies]);

} catch (Exception $e) {
    $logger->log("Error processing request: " . $e->getMessage(), [], 'api', $module, $username);
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while processing your request.']);
}
