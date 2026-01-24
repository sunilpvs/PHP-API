<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class State {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllStates($module, $username) {
        $query = 'SELECT * FROM tbl_state';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedStates($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT 
                    s.id, 
                    s.state, 
                    s.country AS country_id, 
                    c.country AS country 
                  FROM tbl_state s
                  LEFT JOIN tbl_country c ON s.country = c.id
                  ORDER BY s.state 
                  LIMIT $limit OFFSET $offset";

        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getStatesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_state';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getStateById($id, $module, $username) {
        $query = 'SELECT 
                    s.id, 
                    s.state, 
                    s.country AS country_id,
                    c.country AS country
                  FROM tbl_state s
                  LEFT JOIN tbl_country c ON s.country = c.id
                  WHERE s.id = ?';

        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getStatesByCountry($countryId, $module, $username) {
        $query = 'SELECT id, state FROM tbl_state WHERE country = ? ORDER BY state';
        $this->logger->logQuery($query, [$countryId], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$countryId]);
    }

    public function getStateCombo(array $fields, $module, $username) {
        $allowedFields = ['id', 'state', 'country'];
        $query = $this->conn->buildSelectQuery('tbl_state', $fields, $allowedFields, 'state ASC');

        if (!$query) {
            $this->logger->log("Invalid field selection for state combo", 'classes', $module, $username);
            return [];
        }

        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function insertState($state, $countryId, $module, $username) {
        $query = 'INSERT INTO tbl_state (state, country) VALUES (?, ?)';
        $this->logger->logQuery($query, [$state, $countryId], 'classes', $module, $username);
        $logMessage = 'State Inserted ';
        return $this->conn->insert($query, [$state, $countryId], $logMessage);
    }

    public function updateState($state, $countryId, $id, $module, $username) {
        $query = 'UPDATE tbl_state SET state = ?, country = ? WHERE id = ?';
        $this->logger->logQuery($query, [$state, $countryId, $id], 'classes', $module, $username);
        $logMessage = 'State Updated ';
        return $this->conn->update($query, [$state, $countryId, $id], $logMessage);
    }

    public function deleteState($id, $module, $username) {
        $query = 'DELETE FROM tbl_state WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'State Deleted ';
        return $this->conn->update($query, [$id], $logMessage);
    }

    public function checkDuplicateState($state, $countryId) {
        $query = 'SELECT 1 FROM tbl_state WHERE lower(trim(state)) = lower(trim(?)) AND country = ?';
        $this->logger->logQuery($query, [$state, $countryId], 'classes');
        $result = $this->conn->runQuery($query, [$state, $countryId]);
        return !empty($result);
    }
}
