<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';

class ActivityLog {
    private $conn;

    public function __construct() {
        $this->conn = new DBController();
    }

    public function getLogs($limit = 10, $offset = 0, $module = null, $username = null, $fromDate = null, $toDate = null) {
        $query = "SELECT datetime, activity, log, user_id FROM vw_activitylog WHERE 1=1";
        $params = [];

        if ($module) {
            $query .= " AND module = ?";
            $params[] = $module;
        }


        if ($username) {
            $query .= " AND username = ?";
            $params[] = $username;
        }
        

        if ($fromDate) {
            $query .= " AND datetime >= ?";
            $params[] = $fromDate . " 00:00:00";
        }

        if ($toDate) {
            $query .= " AND datetime <= ?";
            $params[] = $toDate . " 23:59:59";
        }

        $query .= " ORDER BY datetime DESC LIMIT $limit OFFSET $offset";

        return $this->conn->runQuery($query, $params);
    }

    public function getTotalCount($module = null, $username = null, $fromDate = null, $toDate = null) {
        $query = "SELECT COUNT(*) AS total FROM vw_activitylog WHERE 1=1";
        $params = [];

        if ($module) {
            $query .= " AND module = ?";
            $params[] = $module;
        }

        if ($username) {
            $query .= " AND username = ?";
            $params[] = $username;
        }

        if ($fromDate) {
            $query .= " AND datetime >= ?";
            $params[] = $fromDate . " 00:00:00";
        }

        if ($toDate) {
            $query .= " AND datetime <= ?";
            $params[] = $toDate . " 23:59:59";
        }

        $result = $this->conn->runQuery($query, $params);
        return $result[0]['total'] ?? 0;
    }

    public function getActivityLogCount($module, $username, $fromDate, $toDate, $logModule, $logUsername) {
        $query = "SELECT COUNT(*) AS total FROM vw_activitylog WHERE 1=1";
        $params = [];

        if ($module) {
            $query .= " AND module = ?";
            $params[] = $module;
        }

        if ($username) {
            $query .= " AND username = ?";
            $params[] = $username;
        }

        if ($fromDate) {
            $query .= " AND datetime >= ?";
            $params[] = $fromDate . " 00:00:00";
        }

        if ($toDate) {
            $query .= " AND datetime <= ?";
            $params[] = $toDate . " 23:59:59";
        }

        $result = $this->conn->runQuery($query, $params);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getVendorActivityLogs($username, $page, $limit, $filterUser, $fromDate, $toDate, $logModule, $logUsername) {
        $offset = ($page - 1) * $limit;
        $query = "SELECT ttl.activity, concat(tc.f_name,' ',tc.l_name) AS username, ttl.datetime FROM tbl_contact tc 
                    JOIN tbl_users tu ON tu.contact_id = tc.id 
                    JOIN tbl_transaction_log ttl
                    ON ttl.action_user_id = tu.id WHERE tu.id = ?";
        $params = [$username];


        if ($filterUser) {
            $query .= " AND username = ?";
            $params[] = $filterUser;
        }

        if ($fromDate) {
            $query .= " AND datetime >= ?";
            $params[] = $fromDate . " 00:00:00";
        }

        if ($toDate) {
            $query .= " AND datetime <= ?";
            $params[] = $toDate . " 23:59:59";
        }

        $query .= " ORDER BY datetime DESC LIMIT $limit OFFSET $offset";

        $result =  $this->conn->runQuery($query, $params);
        return $result;
    }

    public function getVmsActivityLogs($page, $limit, $filterUser, $fromDate, $toDate, $logModule, $logUsername) {
        $offset = ($page - 1) * $limit;
        $query = "SELECT ttl.activity, concat(tc.f_name,' ',tc.l_name) AS username, ttl.datetime FROM tbl_contact tc 
                    JOIN tbl_users tu ON tu.contact_id = tc.id 
                    JOIN tbl_transaction_log ttl ON ttl.action_user_id = tu.id 
                    JOIN tbl_user_modules tum ON tu.id = tum.user_id 
                    WHERE tum.user_role_id IN (6,7,8)";
                    
        $params = [];

        if ($filterUser) {
            $query .= " AND username = ?";
            $params[] = $filterUser;
        }

        if ($fromDate) {
            $query .= " AND datetime >= ?";
            $params[] = $fromDate . " 00:00:00";
        }

        if ($toDate) {
            $query .= " AND datetime <= ?";
            $params[] = $toDate . " 23:59:59";
        }

        $query .= " ORDER BY datetime DESC LIMIT $limit OFFSET $offset";
        
        $result = $this->conn->runQuery($query, $params);
        return $result;
    }

    public function getAdminActivityLogs($page, $limit, $filterUser, $fromDate, $toDate, $logModule, $logUsername) {
        $offset = ($page - 1) * $limit;
        $query = "SELECT ttl.activity, concat(tc.f_name,' ',tc.l_name) AS username, ttl.datetime FROM tbl_contact tc 
                    JOIN tbl_users tu ON tu.contact_id = tc.id 
                    JOIN tbl_transaction_log ttl ON ttl.action_user_id = tu.id 
                    WHERE ttl.action_user_id != 0 AND ttl.action_user_id IS NOT NULL";
                    
        $params = [];

        if ($filterUser) {
            $query .= " AND username = ?";
            $params[] = $filterUser;
        }

        if ($fromDate) {
            $query .= " AND datetime >= ?";
            $params[] = $fromDate . " 00:00:00";
        }

        if ($toDate) {
            $query .= " AND datetime <= ?";
            $params[] = $toDate . " 23:59:59";
        }

        $query .= " ORDER BY datetime DESC LIMIT $limit OFFSET $offset";
       
        $result = $this->conn->runQuery($query, $params);
        return $result;
    }

}
