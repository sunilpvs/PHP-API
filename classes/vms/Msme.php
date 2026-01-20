<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Msme {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    /**
     * Insert MSME details for a vendor
     */
    public function insertMsme($reference_id,$registered_under_msme, $udyam_registration_number, $category, $module, $username) {
        $query = "INSERT INTO vms_msme (
            reference_id, registered_under_msme, udyam_registration_number, category
        ) VALUES (?,?,?,?)";

        $params = [
            $reference_id,
            $registered_under_msme,
            $udyam_registration_number,
            $category                       // Micro, Small, Medium
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'MSME details inserted';
        return $this->conn->insert($query, $params, $logMessage);
    }

    /**
     * Update MSME details
     */
    public function updateMsme($msme_id, $registered_under_msme, $udyam_registration_number, $category, $module, $username) {
        $query = "UPDATE vms_msme SET 
                    registered_under_msme = ?, 
                    udyam_registration_number = ?, 
                    category = ?
                    WHERE msme_id = ?";

        $params = [
            $registered_under_msme,
            $udyam_registration_number,
            $category,
            $msme_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'MSME details updated';
        return $this->conn->update($query, $params, $logMessage);
    }

    /**
     * Get MSME by reference ID
     */
    public function getMsmeByReference($reference_id, $module, $username) {
        $query = "SELECT * FROM vms_msme WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$reference_id]);
    }

    public function getAllMsme($module, $username) {
        $query = "SELECT * FROM vms_msme";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query, []);
    }

    public function duplicateMsmeCheck($reference_id) {
        $query = "SELECT COUNT(*) as count FROM vms_msme WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes');
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result['count'] > 0;
    }
}
?>
