<?php

// This file is for fetching leave requests and different get operations with filters.
// /api/lms/leave/get.php
// for both employees and approvers. Employees can fetch their own leave requests with filters, while approvers can fetch leave requests of their team members with filters.


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Only GET requests are allowed.']);
    exit;
}


require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/lms/LeaveRequests.php';
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
$leaveReqOb = new LeaveRequests();
$auth = new UserLogin();
$email = $auth->getEmailFromJWT() ? $auth->getEmailFromJWT() : 'Guest';
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest';
$token = $auth->getMicrosoftAccessToken();
$module = 'Leave Management System';

try {
    // log the incoming request
    $logger->log("Received $method request at /api/lms/leave/get.php");


    // get all paginated leaves for the logged in user. 
    // This will be used in the employee dashboard to show all leave requests of the employee.
    // if (isset($_GET['type']) && $_GET['type'] === 'all-leave-requests') {
    //     $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    //     $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    //     $offset = ($page - 1) * $limit;

    //     $leaves = $leaveReqOb->getPaginatedLeaveRequests($module, $username, $limit, $offset);
    //     echo json_encode(['data' => $leaves]);
    //     exit;
    // }

    // get all pending leave requests for the approver. 
    // This will be used in the approver dashboard to show all pending leave requests of the team members.
    if (isset($_GET['type']) && $_GET['type'] === 'pending-approvals') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getPendingLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get all approved leave requests for the approver. 
    // This will be used in the approver dashboard to show all approved leave requests of the team members.
    if (isset($_GET['type']) && $_GET['type'] === 'approved-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getApprovedLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get rejecteed leave requests for the approver.
    // This will be used in the approver dashboard to show all rejected leave requests of the team members.
    if (isset($_GET['type']) && $_GET['type'] === 'rejected-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getRejectedLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get leaves for the logged in user.
    if (isset($_GET['type']) && $_GET['type'] === 'my-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getLeavesByUserId($username, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get pending leave requests for the logged in user.
    if (isset($_GET['type']) && $_GET['type'] === 'my-pending-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getLeavesByStatusAndUserId($username, 16, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get approved leave requests for the logged in user.
    if (isset($_GET['type']) && $_GET['type'] === 'my-approved-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getLeavesByStatusAndUserId($username, 17, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get rejected leave requests for the logged in user.
    if (isset($_GET['type']) && $_GET['type'] === 'my-rejected-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getLeavesByStatusAndUserId($username, 18, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get active leave requests for the logged in user.
    if (isset($_GET['type']) && $_GET['type'] === 'my-active-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getActiveLeavesByUserId($username, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get leave history by root request id. 
    // This will be used in the leave details page to show the history of the leave request.
    if (isset($_GET['type']) && $_GET['type'] === 'leave-history' && isset($_GET['root-req-id']) && is_numeric($_GET['root-req-id'])) {
        $rootRequestId = (int)$_GET['root-req-id'];
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getLeaveHistoryByRootRequestIdAndUserId($rootRequestId, $username, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get current effective leave request by root request id and user id.
    if (isset($_GET['type']) && $_GET['type'] === 'current-effective-leave' && isset($_GET['root-req-id']) && is_numeric($_GET['root-req-id'])) {
        $rootRequestId = (int)$_GET['root-req-id'];
        $leaves = $leaveReqOb->getCurrentEffectiveLeaveRequestByRootRequestIdAndUserId($rootRequestId, $username, $module, $username);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get leave report for the logged in user. 
    // This will be used in the reports page to show the leave report of the employee.
    if (isset($_GET['type']) && $_GET['type'] === 'leave-report') {
        $leaves = $leaveReqOb->getLeaveReportByUserId($username, $module, $username);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get leave balance for the logged in user.
    if (isset($_GET['type']) && $_GET['type'] === 'leave-balance') {
        $leaveBalance = $leaveReqOb->calculateLeaveBalance($email, $module, $username);
        echo json_encode(['data' => $leaveBalance]);
        exit;
    }

    // get manager name and email for the logged in user. This will be used in the leave application form to show the manager name and email.
    if (isset($_GET['type']) && $_GET['type'] === 'manager-info') {
        $managerInfo = $leaveReqOb->getManagerInfoForUser($token, $email, $module, $username);
        echo json_encode(['data' => $managerInfo]);
        exit;
    }

    // get approved and active leave requests for the approver
    // This will be used in the modification request form to show the currently approved leave details when the employee wants to apply for a modification request.
    if (isset($_GET['type']) && $_GET['type'] === 'approver-active-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getApprovedAndActiveLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // -- APPROVER OPERATIONS --


    // get pending leave requests for the approver (logged in user).
    // This will be used in the approver dashboard to show all pending leave requests of the team members.
    if (isset($_GET['type']) && $_GET['type'] === 'pending-approvals') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getPendingLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get approved leave requests for the approver (logged in user).
    // This will be used in the approver dashboard to show all approved leave requests of the team members.
    if (isset($_GET['type']) && $_GET['type'] === 'approved-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getApprovedLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get rejected leave requests for the approver (logged in user).
    // This will be used in the approver dashboard to show all rejected leave requests of the team members.
    if (isset($_GET['type']) && $_GET['type'] === 'rejected-leaves') {
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getRejectedLeavesForApproverEmail($email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // get leave history by root request id for the approver (logged in user).
    // This will be used in the leave details page to show the history of the leave request for the approver.
    if (isset($_GET['type']) && $_GET['type'] === 'leave-history' && isset($_GET['root-req-id']) && is_numeric($_GET['root-req-id'])) {
        $rootRequestId = (int)$_GET['root-req-id'];
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $leaves = $leaveReqOb->getLeaveHistoryByRootRequestIdAndApproverEmail($rootRequestId, $email, $module, $username, $limit, $offset);
        echo json_encode(['data' => $leaves]);
        exit;
    }

    // -- END OF APPROVER OPERATIONS --

    // -- ADMIN OPERATIONS --
    // These operations can be performed by the admin to view leave requests of any employee, but for now we are not implementing any admin specific operations. Admin can use the existing endpoints with appropriate filters to view leave requests of any employee.

    // ADMIN can see all the leave requests in the system with pagination
    // but cannot approve or reject any leave request. 
    // He can see all the leave balances of all employees but cannot modify them.


    // -- END OF ADMIN OPERATIONS --

} catch (Exception $e) {
    $logger->log("Error processing request at /api/lms/leave/get.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while processing the request. Please try again later.']);
    exit;
}