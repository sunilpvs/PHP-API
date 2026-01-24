<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';



class Declarations {
    private $conn;
    private $logger;
   

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllDeclarations($module, $username) {
        $query = 'SELECT * FROM vms_declarations';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getDeclarationById($id, $module, $username) {
        $query = 'SELECT * FROM vms_declarations WHERE declaration_id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getDeclarationsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM vms_declarations';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }



    //  declaration_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    // reference_id VARCHAR(20) NOT NULL,
    // primary_declarant_name VARCHAR(255),
    // primary_declarant_designation VARCHAR(255),
    // country_declarant_name VARCHAR(255),
    // country_declarant_designation VARCHAR(255),
    // country_name VARCHAR(255),
    // organisation_name VARCHAR(255),
    // authorized_signatory VARCHAR(255),
    // place VARCHAR(255),
    // signed_date DATE,

    public function getDeclarationByReferenceId($reference_id, $module, $username) {
        $query = "SELECT 
            d.declaration_id,
            d.reference_id,
            d.primary_declarant_name,
            d.primary_declarant_designation,
            d.country_declarant_name,
            d.country_declarant_designation,
            d.country_name,
            d.organisation_name,
            SUBSTRING_INDEX(d.authorized_signatory, '/', -1) as file_name,
            d.authorized_signatory,
            d.place,
            d.signed_date
          FROM vms_declarations d
          LEFT JOIN vms_rfqs v ON d.reference_id = v.reference_id
          WHERE d.reference_id = ?";

        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$reference_id]);
    }

    // final step in vendor registration
    public function insertDeclaration($reference_id, $primary_declarant_name, $primary_declarant_designation, $country_declarant_name, $country_declarant_designation, $country_name, $organisation_name, $authorized_signatory, $place, $signed_date, $module, $username) {
        $query = 'INSERT INTO vms_declarations ('.
            'reference_id, primary_declarant_name, primary_declarant_designation, '.
            'country_declarant_name, country_declarant_designation, country_name, '.
            'organisation_name, authorized_signatory, place, signed_date) '.
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $params = [
            $reference_id,
            $primary_declarant_name,
            $primary_declarant_designation,
            $country_declarant_name,
            $country_declarant_designation,
            $country_name,
            $organisation_name,
            $authorized_signatory,
            $place,
            $signed_date
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Vendor declaration inserted';
        return $this->conn->insert($query, $params, $logMessage);
    }

    public function updateDeclaration($reference_id, $primary_declarant_name, $primary_declarant_designation, $country_declarant_name, $country_declarant_designation, $country_name, $organisation_name, $authorized_signatory, $place, $signed_date, $module, $username) {
        $query = 'UPDATE vms_declarations SET '.
            'primary_declarant_name = ?, primary_declarant_designation = ?, '.
            'country_declarant_name = ?, country_declarant_designation = ?, '.
            'country_name = ?, organisation_name = ?, authorized_signatory = ?, '.
            'place = ?, signed_date = ? '.
            'WHERE reference_id = ?';

        $params = [
            $primary_declarant_name,
            $primary_declarant_designation,
            $country_declarant_name,
            $country_declarant_designation,
            $country_name,
            $organisation_name,
            $authorized_signatory,
            $place,
            $signed_date,
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Vendor declaration updated';
        return $this->conn->update($query, $params, $logMessage);
    }

    
    public function deleteDeclaration($reference_id, $module, $username) {
        $query = 'DELETE FROM vms_declarations WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $logMessage = 'Vendor declaration deleted';
        return $this->conn->update($query, [$reference_id], $logMessage);
    }

    public function getDeclarationByVendor($reference_id, $module, $username) {
        $query = 'SELECT * FROM vms_declarations WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$reference_id]);
    }
}
?>
