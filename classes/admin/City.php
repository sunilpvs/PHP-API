<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class City {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllCities($module,  $username) {
        $query = 'SELECT * FROM tbl_city';
        $this->logger->logQuery($query, [], 'classes', $module,  $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedCities($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $offset);

        $query = "SELECT 
                        c.id, 
                        c.city, 
                        c.state AS state_id, 
                        state.state AS state,
                        c.country AS country_id,
                        ctry.country AS country 
                        FROM tbl_city c
                        LEFT JOIN tbl_state state ON c.state = state.id
                        LEFT JOIN tbl_country ctry ON c.country = ctry.id
                        LIMIT $limit OFFSET $offset";
        
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getCitiesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_city';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getCityById($id, $module, $username) {
        $query = 'SELECT 
                        c.id, 
                        c.city, 
                        c.state AS state_id,
                        c.country AS country_id,
                        state.state AS state, 
                        ctry.country AS country 
                        FROM tbl_city c
                        LEFT JOIN tbl_state state ON c.state = state.id
                        LEFT JOIN tbl_country ctry ON c.country = ctry.id WHERE c.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getCityByIdAndName($id, $city, $module, $username){
        $query = 'SELECT * FROM tbl_city WHERE id = ? AND city = ?';
        $this->logger->logQuery($query, [$id, $city], 'classes', $module,$username);
        return $this->conn->runSingle($query, [$id, $city]);
    }

    public function getCityCombo(array $fields, $module, $username) {
        $allowedFields = ['id', 'city', 'state', 'country'];
        $query = $this->conn->buildSelectQuery('tbl_city', $fields, $allowedFields, 'city ASC');

        if (!$query) {
            $this->logger->log("Invalid field selection for city combo", 'classes', $module, $username);
            return [];
        }
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function insertCity($city, $state, $country, $module, $username) {
        $query = 'INSERT INTO tbl_city (city, state, country) VALUES (?,?,?)';
        
        $this->logger->logQuery($query, [$city, $state, $country], 'classes', $module, $username);
        $logMessage = 'City Inserted ';
        return $this->conn->insert($query, [$city, $state, $country], $logMessage);
    }

    public function updateCity($city, $state, $country,$id, $module,  $username) {
        $query = 'UPDATE tbl_city SET city = ?, state = ?, country = ? WHERE id = ?';
        $this->logger->logQuery($query, [ $city, $state, $country,$id], 'classes', $module, $username);
        $logMessage = 'City Updated ';
        return $this->conn->update($query, [$city, $state, $country,$id], $logMessage);
    }

    public function deleteCity($id, $module,$username) {
        $query = 'DELETE FROM tbl_city WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module,$username);
        $logMessage = 'City Deleted ';
        return $this->conn->update($query, [$id], $logMessage);
    }

    public function checkDuplicateCity($city, $country, $state) {
        $query = 'SELECT 1 FROM tbl_city WHERE lower(trim(city)) = lower(trim(?)) AND country = ? AND state = ?';
        $this->logger->logQuery($query, [$city, $country, $state], 'classes');
        $duplicate = $this->conn->runSingle($query, [$city, $country, $state]);
        return !empty($duplicate);
    }
    
    public function checkEditDuplicateCity($city, $country, $state, $id) {
        $query = 'SELECT 1 FROM tbl_city WHERE lower(trim(city)) = lower(trim(?)) AND country = ? AND state = ? AND id != ?';
        $this->logger->logQuery($query, [$city, $country, $state, $id], 'classes');
        $duplicate = $this->conn->runSingle($query, [$city, $country, $state, $id]);
        return !empty($duplicate);
    }
}
?>