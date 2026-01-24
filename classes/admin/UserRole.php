<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


class UserRole
{

    private $conn;
    private $logger;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getVmsUserRoleByEmail($email, $module, $username){
        $query = "SELECT user_role_id AS role_id FROM tbl_user_modules 
                    WHERE email = ? AND module_id = ? ORDER BY 
                        CASE WHEN user_role_id = ? THEN 1
                        ELSE 2 END LIMIT 1";
        $params = [$email, 4, 6];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runSingle($query, $params);
        return $result ?? null;
    }

    // under development
    public function getVmsManagementUsers($module, $username){
        $query = "SELECT email, user_role_id AS role_id FROM tbl_user_modules 
                    WHERE module_id = ? AND user_role_id IN (2,3)";
        $params = [$module];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runQuery($query, $params);
        return $result ?? null;
    }
}
