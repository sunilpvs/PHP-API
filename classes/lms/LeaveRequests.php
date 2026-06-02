<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphAutoMailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphHelper.php';
// -- Table for leave requests
// CREATE TABLE tbl_leave_requests (
//     id INT PRIMARY KEY AUTO_INCREMENT,
//     leave_title VARCHAR(255) NOT NULL,
//     employee VARCHAR(255) NOT NULL,
//     email_of_employee VARCHAR(255) NOT NULL,
//    user_id INT NOT NULL,
//     start_date DATE NOT NULL,
//     end_date DATE NOT NULL,
//     leave_type INT NOT NULL,
//     reason TEXT NOT NULL,
//     request_type ENUM('NEW', 'MODIFICATION', 'CANCELLATION') NOT NULL,
//     status INT NOT NULL,
//     root_request_id INT DEFAULT NULL,
//     parent_request_id INT DEFAULT NULL,
//     version INT DEFAULT 1,
//     is_active BOOLEAN DEFAULT TRUE,
//     is_latest BOOLEAN DEFAULT TRUE,
//     admin_comments TEXT DEFAULT NULL,
//     approver_name VARCHAR(255) NOT NULL,
//     approver_email VARCHAR(255) NOT NULL,
//     planned_leave_days INT NOT NULL,
//     actual_leave_days INT DEFAULT NULL,
//     adjustment_reason TEXT,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     created_by INT NOT NULL,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     updated_by INT NOT NULL,

//     CONSTRAINT fk_parent_leave_request FOREIGN KEY (parent_request_id) REFERENCES tbl_leave_requests (id)
// );


// -- Table for leave policies
// CREATE TABLE tbl_leave_policy (
//     id INT PRIMARY KEY AUTO_INCREMENT,
//     leave_type INT NOT NULL,
//     annual_quota INT NOT NULL,
//     year INT NOT NULL,
//     carry_forward BOOLEAN NOT NULL,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     created_by INT NOT NULL,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     updated_by INT NOT NULL
// );

// -- Table for leave types
// CREATE TABLE tbl_leave_types (
//     id INT PRIMARY KEY AUTO_INCREMENT,
//     leave_type_name VARCHAR(255) NOT NULL,
//     description TEXT,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     created_by INT NOT NULL,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     updated_by INT NOT NULL
// );

// status 
// 16 - Pending
// 17 - Approved
// 18 - Rejected
// 19 - Cancelled
class LeaveRequests
{
    private $conn;
    private $logger;
    private $graphHelper;

    public function __construct()
    {
        $this->conn = new DBController();
        $this->graphHelper = new GraphHelper();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getPaginatedLeaveRequests($module,  $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name FROM tbl_leave_requests lr JOIN tbl_leave_types lt ON lr.leave_type = lt.id';
        $params = [];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module,  $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getManagerInfoForUser($authToken, $email, $module, $username)
    {
        $approverName = $this->graphHelper->getManagerForUser($authToken, $email, $module, $username)['displayName'] ?? 'Self-Approved';
        $approverEmail = $this->graphHelper->getManagerForUser($authToken, $email, $module, $username)['mail'] ?? null;

        return ['name' => $approverName, 'email' => $approverEmail];
    }

    public function getLeaveRequestById($id, $module, $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.id = ?';
        $params = [$id];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runQuery($query, $params);
        return isset($result[0]) ? $result[0] : null;
    }

    public function getLeaveRequestByRootRequestId($rootRequestId, $module, $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name FROM tbl_leave_requests lr JOIN tbl_leave_types lt ON lr.leave_type = lt.id WHERE lr.root_request_id = ? ORDER BY lr.created_at DESC';
        $params = [$rootRequestId];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getLeaveRequestsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM tbl_leave_requests';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getLeavesByUserId($userId, $module, $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.user_id = ?';
        $params = [$userId];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getLeavesByStatusAndUserId($userId, $status, $module, $username, $limit = null, $offset = null)
    {
        if ($status !== 16 && $status !== 17 && $status !== 18 && $status !== 19) {
            $this->logger->log("Invalid status value: $status. Status should be one of 16 (Pending), 17 (Approved), 18 (Rejected), or 19 (Cancelled).", 'classes', $module, $username);
            return [];
        }

        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.user_id = ? AND lr.status = ?';
        $params = [$userId, $status];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getApprovedAndActiveLeavesForApproverEmail($approverEmail, $module, $username, $limit = null, $offset = null)
    {

        $userIdQuery = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($userIdQuery, [$approverEmail], 'classes', $module, $username);
        $userIdResult = $this->conn->runQuery($userIdQuery, [$approverEmail]);
        $userId = isset($userIdResult[0]['id']) ? (int)$userIdResult[0]['id'] : null;

        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE (lr.approver_email = ? OR lr.user_id = ?) AND lr.status = 17 AND lr.is_active = 1';
        $params = [$approverEmail, $userId];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getActiveLeavesByUserId($userId, $module, $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.user_id = ? AND lr.is_active = 1';
        $params = [$userId];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getLeaveHistoryByRootRequestIdAndUserId($rootRequestId, $userId, $module, $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.root_request_id = ? AND lr.user_id = ? ORDER BY lr.created_at DESC';
        $params = [$rootRequestId, $userId];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }


    public function getLeaveHistoryByRootRequestId($rootRequestId, $module, $username, $limit = null, $offset = null)
    {
        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.root_request_id = ? ORDER BY lr.created_at DESC';
        $params = [$rootRequestId];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getLeaveHistoryByRootRequestIdAndApproverEmail($rootRequestId, $approverEmail, $module, $username, $limit = null, $offset = null)
    {
        $userIdQuery = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($userIdQuery, [$approverEmail], 'classes', $module, $username);
        $userIdResult = $this->conn->runQuery($userIdQuery, [$approverEmail]);
        $userId = isset($userIdResult[0]['id']) ? (int)$userIdResult[0]['id'] : null;

        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.root_request_id = ? AND (lr.user_id = ? OR lr.approver_email = ?)
                    ORDER BY lr.created_at DESC';
        $params = [$rootRequestId, $userId, $approverEmail];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }



    public function getCurrentEffectiveLeaveRequestByRootRequestIdAndUserId($rootRequestId, $userId, $module, $username)
    {
        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE lr.root_request_id = ? 
                    AND lr.user_id = ? AND lr.is_latest = 1 AND lr.is_active = 1';
        $this->logger->logQuery($query, [$rootRequestId, $userId], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$rootRequestId, $userId]);
    }

    public function getPendingLeavesForApproverEmail($approverEmail, $module, $username, $limit = null, $offset = null)
    {
        $userIdQuery = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($userIdQuery, [$approverEmail], 'classes', $module, $username);
        $userIdResult = $this->conn->runQuery($userIdQuery, [$approverEmail]);
        $userId = isset($userIdResult[0]['id']) ? (int)$userIdResult[0]['id'] : null;

        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE (lr.user_id = ? OR lr.approver_email = ?) AND lr.status = 16';
        $params = [$userId, $approverEmail];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }




    public function getApprovedLeavesForApproverEmail($approverEmail, $module, $username, $limit = null, $offset = null)
    {
        $userIdQuery = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($userIdQuery, [$approverEmail], 'classes', $module, $username);
        $userIdResult = $this->conn->runQuery($userIdQuery, [$approverEmail]);
        $userId = isset($userIdResult[0]['id']) ? (int)$userIdResult[0]['id'] : null;

        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE (lr.user_id = ? OR lr.approver_email = ?) 
                    AND lr.status = 17 AND lr.is_active = 1';
        $params = [$userId, $approverEmail];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }

    public function getRejectedLeavesForApproverEmail($approverEmail, $module, $username, $limit = null, $offset = null)
    {
        $userIdQuery = 'SELECT id FROM tbl_users WHERE email = ?';
        $this->logger->logQuery($userIdQuery, [$approverEmail], 'classes', $module, $username);
        $userIdResult = $this->conn->runQuery($userIdQuery, [$approverEmail]);
        $userId = isset($userIdResult[0]['id']) ? (int)$userIdResult[0]['id'] : null;

        $query = 'SELECT lr.*, lt.leave_type_name 
                    FROM tbl_leave_requests lr JOIN tbl_leave_types lt 
                    ON lr.leave_type = lt.id WHERE (lr.user_id = ? OR lr.approver_email = ?) 
                    AND lr.status = 18 AND lr.is_active = 1';
        $params = [$userId, $approverEmail];
        if ($limit !== null) {
            $query .= ' LIMIT ?';
            $params[] = (int)$limit;
        }
        if ($offset !== null) {
            $query .= ' OFFSET ?';
            $params[] = (int)$offset;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        return $this->conn->runQuery($query, $params);
    }


    public function getLeaveReportByUserId($userId, $module, $username)
    {
        $query = 'SELECT COUNT(CASE WHEN lr.status = 16 THEN 1 END) AS pending_count,
                         COUNT(CASE WHEN lr.status = 17 THEN 1 END) AS approved_count,
                         COUNT(CASE WHEN lr.status = 18 THEN 1 END) AS rejected_count,
                         COUNT(CASE WHEN lr.status = 19 THEN 1 END) AS cancelled_count
                  FROM tbl_leave_requests lr
                  WHERE lr.user_id = ?';
        $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$userId]);
    }


    // from the table structure of tbl_leave_requests declared above
    public function createNewLeaveRequest($authToken, $leaveTitle, $emailOfEmployee, $startDate, $endDate, $leaveType, $reason, $createdBy, $module, $username)
    {
        $requestType = 'NEW';
        $status = 16; // Pending status

        // get user id, name, and email of the employee from email
        $query = 'SELECT tu.id, concat(tc.f_name," ",tc.l_name) AS employee from tbl_users tu 
                    join tbl_contact tc 
                    on tu.contact_id = tc.id 
                    where tu.email = ?';
        $this->logger->logQuery($query, [$emailOfEmployee], 'classes', $module, $username);
        $userResult = $this->conn->runQuery($query, [$emailOfEmployee]);
        $userId = isset($userResult[0]['id']) ? (int)$userResult[0]['id'] : null;

        $employee = isset($userResult[0]['employee']) ? $userResult[0]['employee'] : null;

        $approverName = $this->graphHelper->getManagerForUser($authToken, $emailOfEmployee, $module, $username)['displayName'] ?? 'SELF APPROVAL';
        $approverEmail = $this->graphHelper->getManagerForUser($authToken, $emailOfEmployee, $module, $username)['mail'] ?? 'SELF APPROVAL';
        $plannedLeaveDays = $this->calculateActualLeaveDays($authToken, $startDate, $endDate); // for new leave request, planned leave days is same as actual leave days
        $query = 'INSERT INTO tbl_leave_requests 
                    (leave_title, employee, email_of_employee, user_id, start_date, end_date, leave_type, reason, 
                    request_type, status, approver_name, approver_email, planned_leave_days, created_by, updated_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $params = [
            $leaveTitle,
            $employee,
            $emailOfEmployee,
            $userId,
            $startDate,
            $endDate,
            $leaveType,
            $reason,
            $requestType,
            $status,
            $approverName,
            $approverEmail,
            $plannedLeaveDays,
            $createdBy,
            $createdBy
        ];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $insertId = $this->conn->insert($query, $params);

        $query = 'UPDATE tbl_leave_requests SET root_request_id = ? WHERE id = ?';
        $this->logger->logQuery($query, [$insertId, $insertId], 'classes', $module, $username);
        $this->conn->update($query, [$insertId, $insertId]);

        // send mail to manager about the new leave request 
        $mailer = new AutoMail();
        $keyValueData = [
            'Message' => "A new leave request titled '{$leaveTitle}' has been created by {$employee} from {$startDate} to {$endDate}. Reason for leave: {$reason}. Please review and take necessary action.",
            'Planned Leave Days' => $plannedLeaveDays
        ];
        $mailStatus = $mailer->sendInfoEmail(
            subject: "New Leave Request: {$leaveTitle}",
            greetings: "Hello {$approverName},",
            name: "SCBC HR Team",
            keyValueArray: $keyValueData,
            to: [$approverEmail],
            cc: [],
            bcc: []
        );

        if ($insertId && $mailStatus) {
            $this->logger->log("Leave request with ID $insertId created successfully and email sent to approver", 'classes', $module, $username);
            return true;
        } else {
            $this->logger->log("Failed to create leave request or send email for leave request with ID $insertId", 'classes', $module, $username);
            return false;
        }
    }

    public function approveLeaveRequest($id, $adminComments, $updatedBy, $module, $username)
    {
        $leaveRequest = $this->getLeaveRequestById($id, $module, $username);
        if (!$leaveRequest) {
            $this->logger->log("Leave request not found for ID $id", 'classes', $module, $username);
            return false;
        }

        if (($leaveRequest['request_type'] ?? null) !== 'NEW') {
            $this->logger->log("Leave request ID $id is not a new leave request", 'classes', $module, $username);
            return false;
        }

        if ((int)($leaveRequest['status'] ?? 0) !== 16) {
            $this->logger->log("Leave request ID $id is not pending approval", 'classes', $module, $username);
            return false;
        }

        $query = 'SELECT id FROM tbl_users where email = (SELECT approver_email FROM tbl_leave_requests WHERE id = ?)';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $params = [$id];
        $approverId = $this->conn->runSingle($query, $params)['id'] ?? null;
        if ($approverId) {
            $updatedBy = $approverId;
        }

        $status = 17; // Approved status
        $query = 'UPDATE tbl_leave_requests SET status = ?, admin_comments = ?, updated_by = ? WHERE id = ?';
        $this->logger->logQuery($query, [$status, $adminComments, $updatedBy, $id], 'classes', $module, $username);
        $approvedStatus = $this->conn->update($query, [$status, $adminComments, $updatedBy, $id]);

        // send a mail to employee about the approval of leave request
        $employeeEmail = $leaveRequest['email_of_employee'];
        $approverEmail = $leaveRequest['approver_email'];
        $mailer = new AutoMail();
        $keyValueData = [
            'Message' => "Your leave request titled '{$leaveRequest['leave_title']}' from {$leaveRequest['start_date']} to {$leaveRequest['end_date']} has been approved. Admin Comments: {$adminComments}",
        ];
        $mailStatus = $mailer->sendInfoEmail(
            subject: "Leave Request Approved: {$leaveRequest['leave_title']}",
            greetings: "Hello {$leaveRequest['employee']},",
            name: "SCBC HR Team",
            keyValueArray: $keyValueData,
            to: [$employeeEmail],
            cc: [$approverEmail],
            bcc: []
        );

        if ($approvedStatus && $mailStatus) {
            $this->logger->log("Leave request with ID $id approved successfully and email sent to employee", 'classes', $module, $username);
            return true;
        } else {
            $this->logger->log("Failed to approve leave request or send email for leave request with ID $id", 'classes', $module, $username);
            return false;
        }
    }

    public function rejectLeaveRequest($id, $adminComments, $updatedBy, $module, $username)
    {
        $leaveRequest = $this->getLeaveRequestById($id, $module, $username);
        if (!$leaveRequest) {
            $this->logger->log("Leave request not found for ID $id", 'classes', $module, $username);
            return false;
        }

        if (($leaveRequest['request_type'] ?? null) !== 'NEW') {
            $this->logger->log("Leave request ID $id is not a new leave request", 'classes', $module, $username);
            return false;
        }

        if ((int)($leaveRequest['status'] ?? 0) !== 16) {
            $this->logger->log("Leave request ID $id is not pending rejection", 'classes', $module, $username);
            return false;
        }

        $query = 'SELECT id FROM tbl_users where email = (SELECT approver_email FROM tbl_leave_requests WHERE id = ?)';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $params = [$id];
        $rejectionId = $this->conn->runSingle($query, $params)['id'] ?? null;
        if ($rejectionId) {
            $updatedBy = $rejectionId;
        }


        $status = 18; // Rejected status
        $query = 'UPDATE tbl_leave_requests SET status = ?, admin_comments = ?, updated_by = ? WHERE id = ?';
        $this->logger->logQuery($query, [$status, $adminComments, $updatedBy, $id], 'classes', $module, $username);
        $rejectedStatus = $this->conn->update($query, [$status, $adminComments, $updatedBy, $id]);

        // send a mail to employee about the rejection of leave request
        $employeeEmail = $leaveRequest['email_of_employee'];
        $approverEmail = $leaveRequest['approver_email'];
        $mailer = new AutoMail();
        $keyValueData = [
            'Message' => "Your leave request titled '{$leaveRequest['leave_title']}' from {$leaveRequest['start_date']} to {$leaveRequest['end_date']} has been rejected. Admin Comments: {$adminComments}",
        ];
        $mailStatus = $mailer->sendInfoEmail(
            subject: "Leave Request Rejected: {$leaveRequest['leave_title']}",
            greetings: "Hello {$leaveRequest['employee']},",
            name: "SCBC HR Team",
            keyValueArray: $keyValueData,
            to: [$employeeEmail],
            cc: [$approverEmail],
            bcc: []
        );

        if ($rejectedStatus && $mailStatus) {
            $this->logger->log("Leave request with ID $id rejected successfully and email sent to employee", 'classes', $module, $username);
            return true;
        } else {
            $this->logger->log("Failed to reject leave request or send email for leave request with ID $id", 'classes', $module, $username);
            return false;
        }
    }


    public function getLatestLeaveRequestVersion($rootRequestId, $module)
    {
        $query = 'SELECT lr.*, lt.leave_type_name
              FROM tbl_leave_requests lr
              JOIN tbl_leave_types lt
              ON lr.leave_type = lt.id
              WHERE lr.root_request_id = ?
              AND lr.is_latest = 1
              LIMIT 1';

        $this->logger->logQuery(
            $query,
            [$rootRequestId],
            'classes',
            $module
        );

        return $this->conn->runSingle($query, [$rootRequestId]);
    }



    public function createModificationRequest(
        $authToken,
        $rootRequestId,
        $startDate,
        $endDate,
        $adjustmentReason,
        $createdBy,
        $module,
        $username
    ) {

        try {

            // =========================================================
            // 1. Fetch Latest Version
            // =========================================================

            $latestLeaveRequest = $this->getLatestLeaveRequestVersion(
                $rootRequestId,
                $module,
            );

            if (!$latestLeaveRequest) {

                $this->logger->log(
                    "No leave request found for root request ID $rootRequestId",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 2. Validation
            // =========================================================

            if ((int)$latestLeaveRequest['status'] !== 17) {

                $this->logger->log(
                    "Latest leave request is not approved for root request ID $rootRequestId",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            if (!(bool)$latestLeaveRequest['is_active']) {

                $this->logger->log(
                    "Latest leave request is not active for root request ID $rootRequestId",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 3. Prevent Multiple Pending Modifications
            // =========================================================

            $query = 'SELECT COUNT(*) AS total
                  FROM tbl_leave_requests
                  WHERE root_request_id = ?
                  AND request_type = ?
                  AND status = ?';

            $params = [
                $rootRequestId,
                'MODIFICATION',
                16
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $pendingModificationCount = $this->conn->runSingle(
                $query,
                $params
            )['total'];

            if ((int)$pendingModificationCount > 0) {

                $this->logger->log(
                    "Pending modification request already exists for root request ID $rootRequestId",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 4. Date Validation
            // =========================================================

            if (strtotime($endDate) < strtotime($startDate)) {

                $this->logger->log(
                    "Invalid modification dates",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 5. Calculate Leave Days
            // =========================================================

            $plannedLeaveDays = $this->calculateActualLeaveDays(
                $authToken,
                $startDate,
                $endDate
            );

            // get user id of the employee from email
            $query = 'SELECT id FROM tbl_users where email = ?';
            $this->logger->logQuery($query, [$latestLeaveRequest['email_of_employee']], 'classes', $module, $username);
            $userResult = $this->conn->runQuery($query, [$latestLeaveRequest['email_of_employee']]);
            $userId = isset($userResult[0]['id']) ? (int)$userResult[0]['id'] : null;

            // =========================================================
            // 6. Create Modification Request
            // =========================================================

            $requestType = 'MODIFICATION';
            $status = 16; // Pending

            $query = 'INSERT INTO tbl_leave_requests
                    (
                        leave_title,
                        employee,
                        email_of_employee,
                        user_id,
                        start_date,
                        end_date,
                        leave_type,
                        reason,

                        request_type,
                        status,

                        approver_name,
                        approver_email,

                        is_active,
                        is_latest,

                        root_request_id,
                        parent_request_id,
                        version,

                        planned_leave_days,
                        actual_leave_days,

                        adjustment_reason,

                        created_by,
                        updated_by
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?,
                        ?, ?
                    )';

            $params = [

                $latestLeaveRequest['leave_title'],
                $latestLeaveRequest['employee'],
                $latestLeaveRequest['email_of_employee'],
                $userId,

                $startDate,
                $endDate,

                $latestLeaveRequest['leave_type'],
                $latestLeaveRequest['reason'],

                $requestType,
                $status,

                $latestLeaveRequest['approver_name'],
                $latestLeaveRequest['approver_email'],

                0, // is_active
                1, // is_latest

                $latestLeaveRequest['root_request_id'],
                $latestLeaveRequest['id'],
                ((int)$latestLeaveRequest['version']) + 1,

                $plannedLeaveDays,
                $plannedLeaveDays,

                $adjustmentReason,

                $createdBy,
                $createdBy
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $insertId = $this->conn->insert(
                $query,
                $params
            );

            if (!$insertId) {

                $this->logger->log(
                    "Failed to create modification request",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 7. Mark Previous Version As Not Latest
            // =========================================================

            $query = 'UPDATE tbl_leave_requests
                  SET is_latest = ?
                  WHERE id = ?';

            $params = [
                0,
                $latestLeaveRequest['id']
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $this->conn->update(
                $query,
                $params
            );

            // =========================================================
            // 8. Send Mail To Approver
            // =========================================================

            $approverEmail = $latestLeaveRequest['approver_email'];
            $approverName = $latestLeaveRequest['approver_name'];

            $mailer = new AutoMail();

            $keyValueData = [
                'Message' =>
                "A modification request has been created for leave '{$latestLeaveRequest['leave_title']}' by {$latestLeaveRequest['employee']}. Proposed dates are from {$startDate} to {$endDate}.",
                'Planned Leave Days' => $plannedLeaveDays,
                'Adjustment Reason' => $adjustmentReason
            ];

            $mailStatus = $mailer->sendInfoEmail(
                subject: "Modification Request - {$latestLeaveRequest['leave_title']}",
                greetings: "Hello {$approverName},",
                name: "SCBC HR Team",
                keyValueArray: $keyValueData,
                to: [$approverEmail],
                cc: [$latestLeaveRequest['email_of_employee']],
                bcc: []
            );

            if (!$mailStatus) {

                $this->logger->log(
                    "Modification request created but mail sending failed",
                    'classes',
                    $module,
                    $username
                );
            }

            // =========================================================
            // 9. Success
            // =========================================================

            $this->logger->log(
                "Modification request created successfully for root request ID $rootRequestId",
                'classes',
                $module,
                $username
            );

            return true;
        } catch (Exception $e) {

            $this->logger->log(
                "Exception while creating modification request: " . $e->getMessage(),
                'classes',
                $module,
                $username
            );

            return false;
        }
    }



    public function approveModificationRequest(
        $id,
        $adminComments,
        $updatedBy,
        $module,
        $username
    ) {

        try {

            // =========================================================
            // 1. Fetch Modification Request
            // =========================================================

            $modificationRequest = $this->getLeaveRequestById(
                $id,
                $module,
                $username
            );

            if (!$modificationRequest) {

                $this->logger->log(
                    "Modification request not found for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 2. Validation
            // =========================================================

            if ($modificationRequest['request_type'] !== 'MODIFICATION') {

                $this->logger->log(
                    "Request ID $id is not a modification request",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            if ((int)$modificationRequest['status'] !== 16) {

                $this->logger->log(
                    "Modification request is not in pending state for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 3. Fetch Previous Active Version
            // =========================================================

            $parentRequestId = $modificationRequest['parent_request_id'];

            $previousLeaveRequest = $this->getLeaveRequestById(
                $parentRequestId,
                $module,
                $username
            );

            if (!$previousLeaveRequest) {

                $this->logger->log(
                    "Parent leave request not found for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 4. Approve Modification Request
            // =========================================================

            $approvedStatus = 17;

            $query = 'UPDATE tbl_leave_requests
                  SET
                      status = ?,
                      admin_comments = ?,
                      updated_by = ?,
                      is_active = ?,
                      is_latest = ?
                  WHERE id = ?';

            $params = [
                $approvedStatus,
                $adminComments,
                $updatedBy,
                1,
                1,
                $id
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $approveStatus = $this->conn->update(
                $query,
                $params
            );

            // =========================================================
            // 5. Deactivate Previous Version
            // =========================================================

            $query = 'UPDATE tbl_leave_requests
                  SET
                      is_active = ?,
                      is_latest = ?,
                      updated_by = ?
                  WHERE id = ?';

            $params = [
                0,
                0,
                $updatedBy,
                $previousLeaveRequest['id']
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $deactivatePreviousVersion = $this->conn->update(
                $query,
                $params
            );

            // =========================================================
            // 6. Send Mail Notification
            // =========================================================

            $mailer = new AutoMail();

            $keyValueData = [
                'Message' =>
                "Your modification request for leave '{$modificationRequest['leave_title']}' has been approved.",
                'Modified Start Date' => $modificationRequest['start_date'],
                'Modified End Date' => $modificationRequest['end_date'],
                'Admin Comments' => $adminComments
            ];

            $mailStatus = $mailer->sendInfoEmail(
                subject: "Modification Request Approved",
                greetings: "Hello {$modificationRequest['employee']},",
                name: "SCBC HR Team",
                keyValueArray: $keyValueData,
                to: [$modificationRequest['email_of_employee']],
                cc: [$modificationRequest['approver_email']],
                bcc: []
            );

            if (!$mailStatus) {

                $this->logger->log(
                    "Modification approved but email sending failed",
                    'classes',
                    $module,
                    $username
                );
            }

            // =========================================================
            // 7. Success
            // =========================================================

            if ($approveStatus && $deactivatePreviousVersion) {

                $this->logger->log(
                    "Modification request approved successfully for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return true;
            }

            return false;
        } catch (Exception $e) {

            $this->logger->log(
                "Exception while approving modification request: " . $e->getMessage(),
                'classes',
                $module,
                $username
            );

            return false;
        }
    }



    public function rejectModificationRequest(
        $id,
        $adminComments,
        $updatedBy,
        $module,
        $username
    ) {

        try {

            // =========================================================
            // 1. Fetch Modification Request
            // =========================================================

            $modificationRequest = $this->getLeaveRequestById(
                $id,
                $module,
                $username
            );

            if (!$modificationRequest) {

                $this->logger->log(
                    "Modification request not found for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 2. Validation
            // =========================================================

            if ($modificationRequest['request_type'] !== 'MODIFICATION') {

                $this->logger->log(
                    "Request ID $id is not a modification request",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            if ((int)$modificationRequest['status'] !== 16) {

                $this->logger->log(
                    "Modification request is not pending for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return false;
            }

            // =========================================================
            // 3. Reject Modification Request
            // =========================================================

            $rejectedStatus = 18;

            $query = 'UPDATE tbl_leave_requests
                  SET
                      status = ?,
                      admin_comments = ?,
                      updated_by = ?,
                      is_active = ?,
                      is_latest = ?
                  WHERE id = ?';

            $params = [
                $rejectedStatus,
                $adminComments,
                $updatedBy,
                0,
                0,
                $id
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $rejectStatus = $this->conn->update(
                $query,
                $params
            );

            // =========================================================
            // 4. Restore Previous Version As Latest
            // =========================================================

            $query = 'UPDATE tbl_leave_requests
                  SET
                      is_latest = ?,
                      updated_by = ?
                  WHERE id = ?';

            $params = [
                1,
                $updatedBy,
                $modificationRequest['parent_request_id']
            ];

            $this->logger->logQuery(
                $query,
                $params,
                'classes',
                $module,
                $username
            );

            $restorePreviousVersion = $this->conn->update(
                $query,
                $params
            );

            // =========================================================
            // 5. Send Mail Notification
            // =========================================================

            $mailer = new AutoMail();

            $keyValueData = [
                'Message' =>
                "Your modification request for leave '{$modificationRequest['leave_title']}' has been rejected.",
                'Admin Comments' => $adminComments
            ];

            $mailStatus = $mailer->sendInfoEmail(
                subject: "Modification Request Rejected",
                greetings: "Hello {$modificationRequest['employee']},",
                name: "SCBC HR Team",
                keyValueArray: $keyValueData,
                to: [$modificationRequest['email_of_employee']],
                cc: [$modificationRequest['approver_email']],
                bcc: []
            );

            if (!$mailStatus) {

                $this->logger->log(
                    "Modification rejected but email sending failed",
                    'classes',
                    $module,
                    $username
                );
            }

            // =========================================================
            // 6. Success
            // =========================================================

            if ($rejectStatus && $restorePreviousVersion) {

                $this->logger->log(
                    "Modification request rejected successfully for ID $id",
                    'classes',
                    $module,
                    $username
                );

                return true;
            }

            return false;
        } catch (Exception $e) {

            $this->logger->log(
                "Exception while rejecting modification request: " . $e->getMessage(),
                'classes',
                $module,
                $username
            );

            return false;
        }
    }

    // calculate leave balance 
    // the leaves are to be calculated based on the leave types and the number of days taken for each leave type in the current year
    // the leave entitlement for each leave type is to be fetched from the database table tbl_leave_types
    // the number of days taken for each leave type is to be calculated from the tbl_leave_requests table by summing up the actual_leave_days for all approved leave requests of the employee for the current year
    // the function should return an array with leave type as key and remaining leave balance as value
    // only approved leave requests and is_active = 1 should be considered for calculating the leave balance irrespective of the request type (new or modification)
    public function calculateLeaveBalance($emailOfEmployee, $module, $username)
    {
        // get user id of the employee from email
        $query = 'SELECT id FROM tbl_users where email = ?';
        $this->logger->logQuery($query, [$emailOfEmployee], 'classes', $module, $username);
        $userResult = $this->conn->runQuery($query, [$emailOfEmployee]);
        $userId = isset($userResult[0]['id']) ? (int)$userResult[0]['id'] : null;

        //    single query to fetch leaves taken, leaves balance, total leaves for each leave type
        $query = 'SELECT tlt.leave_type_name AS leave_type, SUM(tlr.actual_leave_days) AS leaves_taken, tlp.annual_quota, 
                    (tlp.annual_quota - SUM(tlr.actual_leave_days)) remaining_leaves
                    FROM tbl_leave_requests tlr 
                    JOIN tbl_leave_types tlt ON tlr.leave_type = tlt.id 
                    JOIN tbl_leave_policy tlp ON tlp.leave_type = tlt.id
                    WHERE tlr.is_active = ? AND tlr.status = ? AND (tlr.user_id = ? OR tlr.email_of_employee = ?)
                    AND YEAR(tlr.start_date) = YEAR(CURDATE())
                    GROUP BY tlr.email_of_employee, tlp.annual_quota, tlt.leave_type_name;';
        $params = [1, 17, $userId, $emailOfEmployee];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runQuery($query, $params);

        return $result;
    }


    // function to calculate actual leave days based on start and end date, excluding weekends and holidays
    // holidays are to be fetched from sharepoint list
    // weekends are to be calculated based on the configuration in app.ini file (1st, 2nd, 3rd, 4th, 5th week and days of the week)
    public function calculateActualLeaveDays($authToken, $startDate, $endDate)
    {
        $holidays = $this->getHolidays($authToken, $startDate, $endDate);
        $weekends = $this->getWeekends($startDate, $endDate);

        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $interval = $start->diff($end);
        $totalDays = $interval->days + 1; // include end date

        $actualLeaveDays = $totalDays - $holidays - $weekends;
        return max(0, $actualLeaveDays); // ensure it doesn't go negative
    }

    // returns number of holidays need to be excluded between start and end date by fetching holidays from sharepoint list based on configuration in app.ini file
    public function getHolidays($authToken, $startDate, $endDate)
    {
        try {
            $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
            $siteId = $config['sharepoint-leave-management']['siteId'] ?? null;
            $listId = $config['sharepoint-leave-management']['listId'] ?? null;

            if (!$siteId || !$listId) {
                $this->logger->log(
                    "SharePoint site ID or list ID not configured properly in app.ini",
                    'classes',
                    'LeaveRequests'
                );

                return 0;
            }

            if ($startDate > $endDate) {
                throw new InvalidArgumentException('Start date cannot be greater than end date');
            }

            if ($startDate == null || $endDate == null) {
                throw new InvalidArgumentException('Start date and end date cannot be null');
            }

            require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
            $graph = new \Microsoft\Graph\Graph();
            $graph->setAccessToken($authToken);

            if (!$authToken) {
                $this->logger->log(
                    "No auth token provided for fetching holidays from SharePoint",
                    'classes',
                    'LeaveRequests'
                );

                return 0;
            }

            // optimize the query to fetch only holidays between start and end date
            $filter = "HolidayDate ge '" . $startDate . "' and HolidayDate le '" . $endDate . "'";
            $response = $graph->createRequest("GET", "/sites/$siteId/lists/$listId/items?\$expand=fields&\$filter=$filter")
                ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                ->execute();

            $holidays = 0;
            foreach ($response as $item) {
                $fields = $item->getFields();
                $holidayDate = $fields ? $fields->getProperty('HolidayDate') : null;

                if ($holidayDate) {
                    $holidays++;
                }
            }

            return $holidays;
        } catch (Exception $e) {
            $this->logger->log(
                "Error reading SharePoint configuration: " . $e->getMessage(),
                'classes',
                'LeaveRequests'
            );

            return 0;
        }
    }

    // returns number of weekend days need to be excluded between start and end date
    public function getWeekends($startDate, $endDate)
    {
        try {
            $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);

            $weekendDays = isset($config['excluded-weekends']['days'])
                ? array_map('trim', explode(',', $config['excluded-weekends']['days']))
                : [];

            $weekendWeeks = isset($config['excluded-weekends']['weeks'])
                ? array_map('trim', explode(',', $config['excluded-weekends']['weeks']))
                : [];

            $start = new DateTime($startDate);
            $end = new DateTime($endDate);

            // Include end date
            $end->modify('+1 day');

            $weekendCount = 0;

            while ($start < $end) {

                $dayOfWeek = $start->format('l');


                //   Every Sunday should be excluded

                if ($dayOfWeek === 'Sunday') {
                    $weekendCount++;
                }

                // Saturdays should follow week configuration
                // only runs if the day is saturday and saturday is included in weekend days configuration
                elseif ($dayOfWeek === 'Saturday' && in_array('Saturday', $weekendDays)) {

                    $saturdayOccurrence = 0;

                    // Start from first day of month
                    $tempDate = new DateTime($start->format('Y-m-01'));

                    // Count Saturdays till current date
                    while ($tempDate <= $start) {

                        if ($tempDate->format('l') === 'Saturday') {
                            $saturdayOccurrence++;
                        }

                        $tempDate->modify('+1 day');
                    }

                    // Check if Saturday occurrence matches config
                    if (in_array($saturdayOccurrence, $weekendWeeks)) {
                        $weekendCount++;
                    }
                }

                $start->modify('+1 day');
            }

            return $weekendCount;
        } catch (Exception $e) {

            $this->logger->log(
                "Error calculating weekends: " . $e->getMessage(),
                'classes',
                'LeaveRequests'
            );

            return 0;
        }
    }
}
