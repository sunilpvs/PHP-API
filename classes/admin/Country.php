<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Country {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllCountries($module, $username) {
        $query = 'SELECT * FROM tbl_country';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedCountries($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $offset);

        $query = "SELECT id, country, currency, code FROM tbl_country LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getCountriesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_country';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int) $result[0]['total'] : 0;
    }

    public function getCountryById($id, $module, $username) {
        $query = 'SELECT id, country, currency, code FROM tbl_country WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function insertCountry($country, $currency, $code, $module, $username) {
        $query = 'INSERT INTO tbl_country (country, currency, code) VALUES (?, ?, ?)';
        $this->logger->logQuery($query, [$country, $currency, $code], 'classes', $module, $username);
        $logMessage = 'Country Inserted ';
        return $this->conn->insert($query, [$country, $currency, $code], $logMessage);
    }

    public function updateCountry($country, $currency, $code, $id, $module, $username) {
        $query = 'UPDATE tbl_country SET country = ?, currency = ?, code = ? WHERE id = ?';
        $this->logger->logQuery($query, [$country, $currency, $code, $id], 'classes', $module, $username);
        $logMessage = 'Country Updated ';
        return $this->conn->update($query, [$country, $currency, $code, $id], $logMessage);
    }

    public function deleteCountry($id, $module, $username) {
        $query = 'DELETE FROM tbl_country WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'Country Deleted ';
        return $this->conn->update($query, [$id], $logMessage);
    }

    public function checkDuplicateCountry($country, $currency, $code) {
        $query = 'SELECT 1 FROM tbl_country WHERE lower(trim(country)) = lower(trim(?)) AND lower(trim(currency)) = lower(trim(?)) AND lower(trim(code)) = lower(trim(?))';
        $this->logger->logQuery($query, [$country, $currency, $code], 'classes');
        $result = $this->conn->runSingle($query, [$country, $currency, $code]);
        return !empty($result);
    }


    public function checkEditDuplicateCountry($country, $currency, $code, $id) {
        $query = 'SELECT 1 FROM tbl_country WHERE lower(trim(country)) = lower(trim(?)) AND lower(trim(currency)) = lower(trim(?)) AND lower(trim(code)) = lower(trim(?)) and id != ?';
        $this->logger->logQuery($query, [$country, $currency, $code, $id], 'classes');
        $result = $this->conn->runSingle($query, [$country, $currency, $code, $id]);

        return !empty($result);
    }

    public function getCountryCombo(array $fields, $module, $username) {
        $allowedFields = ['id', 'country', 'currency', 'code'];
        $query = $this->conn->buildSelectQuery('tbl_country', $fields, $allowedFields, 'country ASC');

        if (!$query) {
            $this->logger->log("Invalid field selection for country combo", 'classes', $module, $username);
            return [];
        }

        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }
}
