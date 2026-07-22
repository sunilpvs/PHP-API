<?php 

class LookupCache {
    private $conn;
    private $logger;

    private $departments = [];
    private $designations = [];
    private $entities = [];
    private $statuses = [];
    private $locations = [];
    private $contactTypes = [];
    private $employeesByOldEmployeeCodeMap = [];
    private $employeesByEmpCodeMap = [];
    
    public function __construct($conn, $logger) {
        $this->conn = $conn;
        $this->logger = $logger;
    }

    public function load() {
        $this->loadDepartments();
        $this->loadDesignations();
        $this->loadEntities();
        $this->loadStatuses();
        $this->loadLocations();
        $this->loadContactTypes();
        $this->loadEmployeesByOldEmployeeCode();
        $this->loadEmployeesByEmpCode();
    }

    private function normalizeKey($value) {
        return strtolower(trim((string)$value));
    }

    private function loadDepartments() {
        $query = 'SELECT id, name, code FROM tbl_department';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);

        $this->departments = [];
        foreach ($rows as $row) {
            $this->departments[$this->normalizeKey($row['name'])] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'code' => $row['code'],
            ];
        }
    }

    private function loadDesignations() {
        $query = 'SELECT id, name, code FROM tbl_designation';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);

        $this->designations = [];
        foreach ($rows as $row) {
            $this->designations[$this->normalizeKey($row['name'])] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'code' => $row['code'],
            ];
        }
    }

    // LOAD LOCATIONS: only city name is sent as input and based on that city(id), state, country is fetched from tbl_city table
    // store state id and country id in the locations array
    private function loadLocations() {
        $query = 'SELECT id, city, state, country FROM tbl_city';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);
            $this->locations = [];
        foreach ($rows as $row) {
            $this->locations[$this->normalizeKey($row['city'])] = [
                'city_id' => (int)$row['id'],
                'state_id' => (int)$row['state'],
                'country_id' => (int)$row['country'],
            ];
        }
    }

    private function loadEntities() {
        $query = 'SELECT id, entity_name, entity_code FROM tbl_entity';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);

        $this->entities = [];
        foreach ($rows as $row) {
            $entry = [
                'id' => (int)$row['id'],
                'entity_name' => $row['entity_name'],
                'entity_code' => $row['entity_code'],
            ];

            $nameKey = $this->normalizeKey($row['entity_name']);
            if ($nameKey !== '') {
                $this->entities[$nameKey] = $entry;
            }

            $codeKey = $this->normalizeKey($row['entity_code']);
            if ($codeKey !== '') {
                $this->entities[$codeKey] = $entry;
            }
        }
    }

    private function loadStatuses() {
        $query = "SELECT id, status, module FROM tbl_status WHERE module = 'GEN'";
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);

        $this->statuses = [];
        foreach ($rows as $row) {
            $this->statuses[$this->normalizeKey($row['status'])] = [
                'id' => (int)$row['id'],
                'status' => $row['status'],
                'module' => $row['module'],
            ];
        }
    }

    private function loadContactTypes() {
        $query = 'SELECT id, name FROM tbl_contacttype';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);

        $this->contactTypes = [];
        foreach ($rows as $row) {
            $this->contactTypes[$this->normalizeKey($row['name'])] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
            ];
        }
    }

    private function loadEmployeesByOldEmployeeCode() {
        $query = 'SELECT id, old_emp_code FROM tbl_employee';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);
        $this->employeesByOldEmployeeCodeMap = [];
        foreach ($rows as $row) {
            $this->employeesByOldEmployeeCodeMap[$this->normalizeKey($row['old_emp_code'])] = [
                'id' => (int)$row['id'],
                'old_emp_code' => $row['old_emp_code'],
            ];
        }
    }
    
    private function loadEmployeesByEmpCode() {
        $query = 'SELECT id, emp_code FROM tbl_employee';
        $this->logger->logQuery($query, [], 'classes', 'lookup-cache');
        $rows = $this->conn->runQuery($query);
        $this->employeesByEmpCodeMap = [];
        foreach ($rows as $row) {
            $this->employeesByEmpCodeMap[$this->normalizeKey($row['emp_code'])] = [
                'id' => (int)$row['id'],
                'emp_code' => $row['emp_code'],
            ];
        }
    }

    public function getDepartmentId($name) {
        $key = $this->normalizeKey($name);
        return isset($this->departments[$key]) ? $this->departments[$key]['id'] : null;
    }

    public function getDesignationId($name) {
        $key = $this->normalizeKey($name);
        return isset($this->designations[$key]) ? $this->designations[$key]['id'] : null;
    }

    public function getEntityId($nameOrCode) {
        $key = $this->normalizeKey($nameOrCode);
        return isset($this->entities[$key]) ? $this->entities[$key]['id'] : null;
    }

    public function getStatusId($statusName) {
        $key = $this->normalizeKey($statusName);
        return isset($this->statuses[$key]) ? $this->statuses[$key]['id'] : null;
    }

    public function getLocationCityId($cityName) {
        $key = $this->normalizeKey($cityName);
        return isset($this->locations[$key]) ? $this->locations[$key]['city_id'] : null;
    }

    public function getLocationStateId($cityName) {
        $key = $this->normalizeKey($cityName);
        return isset($this->locations[$key]) ? $this->locations[$key]['state_id'] : null;
    }

    public function getLocationCountryId($cityName) {
        $key = $this->normalizeKey($cityName);
        return isset($this->locations[$key]) ? $this->locations[$key]['country_id'] : null;
    }
    
    public function getContactTypeId($name) {
        $key = $this->normalizeKey($name);
        return isset($this->contactTypes[$key]) ? $this->contactTypes[$key]['id'] : null;
    }

    public function getEmployeeIdByOldEmployeeCode($oldEmployeeCode) {
        $key = $this->normalizeKey($oldEmployeeCode);
        return isset($this->employeesByOldEmployeeCodeMap[$key]) ? $this->employeesByOldEmployeeCodeMap[$key]['id'] : null;
    }

    public function getEmployeeIdByEmpCode($empCode) {
        $key = $this->normalizeKey($empCode);
        return isset($this->employeesByEmpCodeMap[$key]) ? $this->employeesByEmpCodeMap[$key]['id'] : null;
    }
}
