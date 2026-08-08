<?php 

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class OfficeLocations {
	private $conn;
	private $logger;

	public function __construct() {
		$this->conn = new DBController();
		$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
		$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
		$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
		$this->logger = new Logger($debugMode, $logDir);
	}

// -- Table structure for table `mas_office_location`
// CREATE TABLE `mas_office_location` (
//   `id` int(11) NOT NULL,
//   `name` varchar(25) NOT NULL,
//   `address` varchar(50) DEFAULT NULL,
//   `city` varchar(25) DEFAULT NULL,
//   `state` varchar(25) NOT NULL,
//   `zip` varchar(15) NOT NULL,
//   `country` varchar(15) NOT NULL,
//   `office` varchar(50) NOT NULL,
//   `status` int(11) NOT NULL DEFAULT '1',
//   `createdBy` int(11) NOT NULL,
//   `created_datetime` datetime DEFAULT current_timestamp(),
//   `last_updated` int(11) DEFAULT NULL,
//   `last_updatedDatetime` datetime DEFAULT NULL
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

	public function getAllOfficeLocations($module, $username) {
		$query = 'SELECT * FROM mas_office_location ORDER BY id DESC';
		$this->logger->logQuery($query, [], 'classes', $module, $username);
		return $this->conn->runQuery($query);
	}

	public function getPaginatedOfficeLocations($offset, $limit, $module, $username) {
		$limit = max(1, min(100, (int) $limit));
		$offset = max(0, (int) $offset);

		$query = "SELECT 
					id,
					name,
					address,
					city,
					state,
					zip,
					country,
					office,
					status,
					createdBy,
					created_datetime,
					last_updated,
					last_updatedDatetime
				  FROM mas_office_location
				  ORDER BY id DESC
				  LIMIT $limit OFFSET $offset";

		$this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
		return $this->conn->runQuery($query);
	}

	public function getOfficeLocationsCount($module, $username) {
		$query = 'SELECT COUNT(*) AS total FROM mas_office_location';
		$this->logger->logQuery($query, [], 'classes', $module, $username);
		$result = $this->conn->runQuery($query);
		return isset($result[0]['total']) ? (int) $result[0]['total'] : 0;
	}

	public function getOfficeLocationById($id, $module, $username) {
		$query = 'SELECT 
					id,
					name,
					address,
					city,
					state,
					zip,
					country,
					office,
					status,
					createdBy,
					created_datetime,
					last_updated,
					last_updatedDatetime
				  FROM mas_office_location
				  WHERE id = ?';
		$this->logger->logQuery($query, [$id], 'classes', $module, $username);
		return $this->conn->runSingle($query, [$id]);
	}

	public function getOfficeLocationByIdAndName($id, $name, $module, $username) {
		$query = 'SELECT * FROM mas_office_location WHERE id = ? AND name = ?';
		$this->logger->logQuery($query, [$id, $name], 'classes', $module, $username);
		return $this->conn->runSingle($query, [$id, $name]);
	}

	public function getOfficeLocationCombo(array $fields, $module, $username) {
		$query = "SELECT id, name FROM mas_office_location WHERE status = 1 ORDER BY name ASC";
		$this->logger->logQuery($query, [], 'classes', $module, $username);
		return $this->conn->runQuery($query);
	}

	public function insertOfficeLocation($name, $address, $city, $state, $zip, $country, $office, $status, $createdBy, $module, $username) {
		$query = 'INSERT INTO mas_office_location (name, address, city, state, zip, country, office, status, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
		$params = [$name, $address, $city, $state, $zip, $country, $office, $status, $createdBy];

		$this->logger->logQuery($query, $params, 'classes', $module, $username);
		$logMessage = 'Office Location Inserted ';
		return $this->conn->insert($query, $params, $logMessage);
	}

	public function updateOfficeLocation($name, $address, $city, $state, $zip, $country, $office, $status, $id, $lastUpdated, $module, $username) {
		$query = 'UPDATE mas_office_location SET name = ?, address = ?, city = ?, state = ?, zip = ?, country = ?, office = ?, status = ?, last_updated = ?, last_updatedDatetime = NOW() WHERE id = ?';
		$params = [$name, $address, $city, $state, $zip, $country, $office, $status, $lastUpdated, $id];

		$this->logger->logQuery($query, $params, 'classes', $module, $username);
		$logMessage = 'Office Location Updated ';
		return $this->conn->update($query, $params, $logMessage);
	}

	public function deleteOfficeLocation($id, $module, $username) {
		$query = 'DELETE FROM mas_office_location WHERE id = ?';
		$this->logger->logQuery($query, [$id], 'classes', $module, $username);
		$logMessage = 'Office Location Deleted ';
		return $this->conn->update($query, [$id], $logMessage);
	}

	public function checkDuplicateOfficeLocation($name, $city, $state, $country, $office) {
		$query = 'SELECT 1 FROM mas_office_location WHERE lower(trim(name)) = lower(trim(?)) AND lower(trim(city)) = lower(trim(?)) AND lower(trim(state)) = lower(trim(?)) AND lower(trim(country)) = lower(trim(?)) AND lower(trim(office)) = lower(trim(?))';
		$params = [$name, $city, $state, $country, $office];

		$this->logger->logQuery($query, $params, 'classes');
		$duplicate = $this->conn->runSingle($query, $params);
		return !empty($duplicate);
	}

	public function checkEditDuplicateOfficeLocation($name, $city, $state, $country, $office, $id) {
		$query = 'SELECT 1 FROM mas_office_location WHERE lower(trim(name)) = lower(trim(?)) AND lower(trim(city)) = lower(trim(?)) AND lower(trim(state)) = lower(trim(?)) AND lower(trim(country)) = lower(trim(?)) AND lower(trim(office)) = lower(trim(?)) AND id != ?';
		$params = [$name, $city, $state, $country, $office, $id];

		$this->logger->logQuery($query, $params, 'classes');
		$duplicate = $this->conn->runSingle($query, $params);
		return !empty($duplicate);
	}
}



?>