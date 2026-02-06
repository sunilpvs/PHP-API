<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphAutoMailer.php';
require $_SERVER['DOCUMENT_ROOT'] . "/vendor/autoload.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Rfq.php';


use Dotenv\Dotenv;

$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../../', '.env.prod');
} else {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../../', '.env');
}

$dotenv->load();


class AccessRequest
{
    private $conn;
    private $logger;
    private $app_url;
    private $vms_url;
    private $rfqData;


    public function __construct()
    {
        // $this->mailer = new AutoMail();
        $this->conn = new DBController();
        $this->rfqData = new Rfq();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';

        $this->logger = new Logger($debugMode, $logDir);
        $this->app_url = $_ENV['ADMIN_PORTAL_URL'] ?? '';
        $this->vms_url = $_ENV['VMS_PORTAL_URL'] ?? '';
    }

    public function getAllAccessRequests($module, $username)
    {
        $query = "SELECT concat(a.f_name,' ',a.l_name) AS requestor_name, b.module_name AS module, c.status AS status, d.email FROM tbl_access_requests d
                        JOIN tbl_contact a ON d.contact_id = a.id 
                        JOIN tbl_module b ON d.requested_module = b.module_id
                        JOIN tbl_status c ON d.status = c.id;";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedAccessRequests($module, $username, $limit, $offset)
    {
        $query = "SELECT concat(a.f_name,' ',a.l_name) AS requestor_name, b.module_name AS module, c.status AS status, d.email FROM tbl_access_requests d
                        JOIN tbl_contact a ON d.contact_id = a.id 
                        JOIN tbl_module b ON d.requested_module = b.module_id
                        JOIN tbl_status c ON d.status = c.id
                        LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getAccessRequestById($request_id, $module, $username)
    {
        $query = "SELECT * FROM tbl_access_requests WHERE id = ?";
        $this->logger->logQuery($query, [$request_id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$request_id]);
    }

    public function getAllUsers($module, $username)
    {
        $query = "SELECT concat(a.f_name,' ',a.l_name) as username, b.email as user_email, c.module_name as access_level, b.module_id as module_id, d.user_role as user_role from tbl_user_modules b
                    join tbl_module c on b.module_id = c.module_id
                    join tbl_users user on b.user_id = user.id 
                    join tbl_contact a on user.email = a.email
                    join tbl_user_role d on b.user_role_id = d.id WHERE b.enabled=1 AND user.id NOT IN (1,2) ";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedUsers($module, $username, $limit, $offset)
    {
        $query = "SELECT concat(a.f_name,' ',a.l_name) as username, b.email as user_email, c.module_name as access_level, b.module_id as module_id, d.user_role as user_role from tbl_user_modules b
                    join tbl_module c on b.module_id = c.module_id
                    join tbl_users user on b.user_id = user.id 
                    join tbl_contact a on user.email = a.email
                    join tbl_user_role d on b.user_role_id = d.id WHERE b.enabled=1 AND user.id NOT IN (1,2) 
                    LIMIT $limit OFFSET $offset ";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query,);
    }

    public function getTotalUsersCount($module, $username)
    {
        $query = "SELECT COUNT(*) AS total FROM tbl_user_modules WHERE enabled=1";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runSingle($query);
        return isset($result['total']) ? (int)$result['total'] : 0;
    }

    public function getITAdminEmails($module = 'Access Request', $username = 'guest')
    {
        $emails = "";
        $query = "SELECT email FROM tbl_user_modules WHERE user_role_id = 2";
        $this->logger->logQuery($query, [], 'classes', 'Access request', 'guest');
        $results = $this->conn->runQuery($query);
        if (is_array($results) && count($results) > 0) {
            foreach ($results as $row) {
                if (isset($row['email'])) {
                    $emails .= "" . $row['email'] . ",";
                }
            }
        }
        return rtrim($emails, ',');
    }

    public function checkExistingAdminRequest($email, $module, $username)
    {
        // 1 for admin module
        $query = "SELECT COUNT(*) AS total FROM tbl_access_requests WHERE email = ? AND requested_module = 1";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['total']) ? (int)$result['total'] : 0;
    }

    public function checkExistingVmsRequest($email, $module, $username)
    {
        // 4 for vms module
        $query = "SELECT COUNT(*) AS total FROM tbl_access_requests WHERE email = ? AND requested_module = 4";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['total']) ? (int)$result['total'] : 0;
    }

    public function checkExistingAmsRequest($email, $module, $username)
    {
        // 5 for ams module
        $query = "SELECT COUNT(*) AS total FROM tbl_access_requests WHERE email = ? AND requested_module = 5";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['total']) ? (int)$result['total'] : 0;
    }

    public function getAccessRequestsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM tbl_access_requests';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getPendingAccessRequestsCount($module, $username)
    {
        $query = "SELECT COUNT(*) AS total FROM tbl_access_requests WHERE status = 8";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    // Under Maintenance: get name from the users table
    public function getRequestorNameByEmail($email, $module, $username)
    {
        $query = "SELECT a.f_name, a.l_name FROM tbl_contact a
                  WHERE a.email = ?";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        $emp_name = $result ? trim($result['f_name'] . ' ' . $result['l_name']) : null;
        return $emp_name;
    }

    public function deleteUser($email, $user_module_id, $module, $username)
    {
        $query = "DELETE FROM tbl_user_modules WHERE email = ? AND module_id = ?";
        $this->logger->logQuery($query, [$email, $user_module_id], 'classes', $module, $username);
        $deletedId = $this->conn->delete($query, [$email, $user_module_id], 'User module access deleted');
        if (!$deletedId) {
            return false;
        }

        $query = "UPDATE tbl_access_requests SET status = 8, approver_id = NULL, approver_name = NULL, approver_email = NULL WHERE email = ? AND requested_module = ?";
        $this->logger->logQuery($query, [$email, $user_module_id], 'classes', $module, $username);
        $updatedId = $this->conn->update($query, [$email, $user_module_id], 'Access request status reset');
        if (!$updatedId) {
            return false;
        }

        return true;
    }

    public function checkVendorStatus($email, $module, $username)
    {
        // 4 for vms module
        $query = "SELECT COUNT(*) AS total FROM tbl_user_modules WHERE email = ? AND module_id = 4";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['total']) ? (int)$result['total'] : 0;
    }


    public function checkUserModuleExist($email, $module_id, $module, $username)
    {
        $query = "SELECT COUNT(*) AS total FROM tbl_user_modules WHERE email = ? AND module_id = ?";
        $this->logger->logQuery($query, [$email, $module_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email, $module_id]);
        return isset($result['total']) ? (int)$result['total'] : 0;
    }

    public function getContactIdfromEmail($email, $module, $username)
    {
        $query = "SELECT id FROM tbl_contact WHERE email = ?";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return $result ? $result['id'] : null;
    }

    public function getUserIdfromEmail($email, $module, $username)
    {
        $query = "SELECT id FROM tbl_users WHERE email = ?";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return $result ? $result['id'] : null;
    }

    public function insertAccessRequest($requester_email, $contactId, $requested_module,  $module, $username)
    {
        $query = 'INSERT INTO tbl_access_requests (
                    email, contact_id, requested_module, status) 
                    VALUES (?, ?, ?, 8)';

        $params = [$requester_email, $contactId, $requested_module];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Access request inserted';
        $insertId = $this->conn->insert($query, $params, $logMessage);

        // Send mail to IT Admin
        $mailer = new AutoMail();
        $emp_name = self::getRequestorNameByEmail($requester_email, $module, $username);

        if ($requested_module == 4) {
            $requested_module_name = "Vendor Management System";
        } else if ($requested_module == 1) {
            $requested_module_name = "Admin Portal";
        } else if ($requested_module == 3) {
            $requested_module_name = "Warehouse Management System";
        } else if ($requested_module == 5) {
            $requested_module_name = "Asset Management System";
        } else {
            $requested_module_name = "Unknown Module";
        }

        $keyValueData = [
            "Message" => "$emp_name has requested access to the $requested_module_name. 
                            Please review and approve the request. Login to the system to manage the access requests.",
            "Employee Name" => $emp_name,
            "Approval Link" => $this->app_url,
        ];

        // $emails = self::getITAdminEmails();
        $ItAdminEmails = $this->rfqData->getITAdminEmails('access request', 'system');
        // $emailArray = explode(',', $emails);

        $response = $mailer->sendInfoEmail(
            subject: "New Access Request for $requested_module_name",
            greetings: "Dear IT Admin,",
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: $ItAdminEmails,
            cc: [], // remove this in production
            bcc: $ItAdminEmails,
        );
        // echo $response;

        if ($response) {
            $updateQuery = 'UPDATE tbl_access_requests SET status = 8 WHERE id = ?';
            $this->logger->logQuery($updateQuery, [$insertId], 'classes', $module, $username);
            $updatedId = $this->conn->update($updateQuery, [$insertId], 'Email sent status updated');
        }

        // send a confirmation email to the requester

        if ($insertId && $response) {
            return $insertId;
        } else {
            return false;
        }
    }

    public function updateAccessRequestStatus($request_id, $user_role_id, $status, $module, $username)
    {
        $query = "SELECT a.email, a.requested_module as requested_module_id, b.module_name AS requested_module FROM tbl_access_requests a 
                    JOIN tbl_module b ON a.requested_module = b.module_id WHERE a.id = ?";
        $this->logger->logQuery($query, [$request_id], 'classes', $module, $username);
        $requestDetails = $this->conn->runSingle($query, [$request_id]);

        if (!$requestDetails) {
            return false;
        }

        // Requestor Details
        $requestor_email = $requestDetails['email'];
        $requested_module = $requestDetails['requested_module'];
        $requested_module_id = $requestDetails['requested_module_id'];

        // Approver Details
        $approver_id = self::getUserIdfromEmail($username, $module, $username);
        $approver_name = self::getRequestorNameByEmail($username, $module, $username);
        $approver_email = $username;

        if ($status == 11) {
            $statusMessage = 'approved';
            $statusCode = 11;
        } elseif ($status == 12) {
            $statusMessage = 'rejected';
            $statusCode = 12;
        } else {
            return false;
        }

        $updateQuery = "UPDATE tbl_access_requests SET status = ?, approver_id = ?, approver_name = ?, approver_email = ?  WHERE id = ?";
        $this->logger->logQuery($updateQuery, [$statusCode, $approver_id, $approver_name, $approver_email, $request_id,], 'classes', $module, $username);
        $updatedId = $this->conn->update($updateQuery, [$statusCode, $approver_id, $approver_name, $approver_email, $request_id], 'Updated access request status');

        // If approved, update the user's module and role
        if ($updatedId && $status == 11) {
            $userId = self::getUserIdfromEmail($requestor_email, $module, $username);
            $query = "INSERT INTO tbl_user_modules(user_id, email, module_id, user_role_id, created_by, enabled)
                        VALUES(?, ?, $requested_module_id, ?, ?, 1)";
            $this->logger->logQuery($query, [$userId, $requestor_email, $user_role_id, $username], 'classes', $module, $username);
            $params = [$userId, $requestor_email, $user_role_id, $username];
            $logMessage = 'User module and role assigned upon access approval';
            $insertId = $this->conn->insert($query, $params, $logMessage);
        } else if ($updatedId && $status == 12) {
            // If rejected, no changes to user modules
        }

        if($requested_module_id == 4){
            $requested_module_name = "Vendor Management System";
        } else if($requested_module_id == 1){
            $requested_module_name = "Admin Portal";
        } else if($requested_module_id == 3){
            $requested_module_name = "Warehouse Management System";
        } else if($requested_module_id == 5){
            $requested_module_name = "Asset Management System";
        } else {
            $requested_module_name = "Unknown Module";
        }

        // Get the Requestor Name
        $req_name = self::getRequestorNameByEmail($requestor_email, $module, $username);

        // Send email to the requestor about the status update
        if ($statusMessage == 'approved') {
            $keyValueData = [
                "Message" =>  "Your request for access to the $requested_module - $requested_module_name has been $statusMessage. 
                                Please login to the system to view the status of your requests.",
                "Employee Name" => $req_name,

                "VMS Portal Link" => $this->vms_url,
            ];
        } else {
            $keyValueData = [
                "Message" => "Your request for access to the $requested_module - $requested_module_name has been $statusMessage. 
                                For more details, please contact the IT department.",
                "Employee Name" => $req_name,
            ];
        }
        $ItAdminEmails = $this->rfqData->getITAdminEmails('access request', 'system');
        $mailer = new AutoMail();

        $response = $mailer->sendInfoEmail(
            subject: "Your Access Request for $requested_module has been $statusMessage",
            greetings: "Dear $req_name,",
            name: 'Shrichandra Group Team',
            keyValueArray: $keyValueData,
            to: [$requestor_email],
            cc: [], // remove this in production
            bcc: $ItAdminEmails,
        );

        if ($response) {
            $this->logger->log('Email sent to requestor: ' . $requestor_email, 'email', $module, $username);
            return true;
        }

        return false;
    }


    public function getAdminAccessStatus($email, $module, $username)
    {
        // 1 for admin module
        $query = "SELECT b.status FROM tbl_access_requests a JOIN tbl_status b ON a.status = b.id WHERE email = ? AND requested_module = 1 ";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return $result ? $result['status'] : null;
    }

    public function getVmsAccessStatus($email, $module, $username)
    {
        // 4 for vms module
        $query = "SELECT b.status FROM tbl_access_requests a JOIN tbl_status b ON a.status = b.id WHERE email = ? AND requested_module = 4";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return $result ? $result['status'] : null;
    }

    public function getAmsAccessStatus($email, $module, $username)
    {
        // 5 for ams module
        $query = "SELECT b.status FROM tbl_access_requests a JOIN tbl_status b ON a.status = b.id WHERE email = ? AND requested_module = 5";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return $result ? $result['status'] : null;
    }


    public function checkVmsAccess($email, $module, $username)
    {
        $query = "SELECT COUNT(*) AS access FROM tbl_user_modules WHERE email = ? AND module_id = 4 AND user_role_id IN (6,7)";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['access']) ? (int)$result['access'] : 0;
    }

    public function checkAmsAccess($email, $module, $username)
    {
        $query = "SELECT COUNT(*) AS access FROM tbl_user_modules WHERE email = ? AND module_id = 5 AND user_role_id IN (9,10)";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['access']) ? (int)$result['access'] : 0;
    }

    public function checkAdminAccess($email, $module, $username)
    {
        $query = "SELECT COUNT(*) AS access FROM tbl_user_modules WHERE email = ? AND module_id = 1 AND user_role_id IN(1,2)";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$email]);
        return isset($result['access']) ? (int)$result['access'] : 0;
    }


    public function getPendingAccessRequests($module, $username)
    {
        $query = "SELECT a.module_name AS requested_module, b.id, b.email, concat(c.f_name, ' ',c.l_name) AS requestor_name FROM tbl_access_requests b 
                    JOIN tbl_contact c ON b.contact_id = c.id
                    JOIN tbl_module a ON b.requested_module = a.module_id
                    WHERE b.status=8";

        $this->logger->logQuery($query, [], 'classes', 'Access request', 'guest');
        return $this->conn->runQuery($query);
    }

    public function getRoleIdFromEmail($email, $module, $username)
    {
        $query = "SELECT DISTINCT(user_role_id) AS user_role FROM tbl_user_modules WHERE email = ?";
        $this->logger->logQuery($query, [$email], 'classes', $module, $username);
        $result = $this->conn->runQuery($query, [$email]);
        return isset($result[0]['user_role']) ? (int)$result[0]['user_role'] : null;
    }
}
