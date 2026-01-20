<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class CostCenter {

    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1','true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllCostCenters($module, $username) {
        $query = "SELECT 
                    cc.id,
                    cc.cc_code,
                    cc.cc_type AS cc_type_id,
                    cct.cc_type AS cc_type,
                    cc.entity_id,
                    ent.entity_name,
                    cc.incorp_date,
                    cc.gst_no,
                    cc.add1, cc.add2,
                    cc.city AS city_id,
                    ci.city AS city,
                    cc.state AS state_id,
                    st.state AS state,
                    cc.country AS country_id,
                    ctry.country AS country,
                    cc.pin,
                    cc.primary_contact AS contact_id,
                    CONCAT(a.f_name,' ',a.l_name) AS primary_contact,
                    cc.status AS status_id,
                    s.status AS status
                  FROM tbl_costcenter cc
                  LEFT JOIN tbl_costcentertype cct ON cc.cc_type = cct.id
                  LEFT JOIN tbl_entity ent ON cc.entity_id = ent.id
                  LEFT JOIN tbl_city ci ON cc.city = ci.id
                  LEFT JOIN tbl_state st ON cc.state = st.id
                  LEFT JOIN tbl_country ctry ON cc.country = ctry.id
                  LEFT JOIN tbl_contact ct ON cc.primary_contact = ct.id
                  LEFT JOIN tbl_status s ON cc.status = s.code";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedCostCenters($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT 
                    cc.id,
                    cc.cc_code,
                    cc.cc_type AS cc_type_id,
                    cct.cc_type AS cc_type,
                    cc.entity_id,
                    ent.entity_name,
                    cc.incorp_date,
                    cc.gst_no,
                    cc.add1, cc.add2,
                    cc.city AS city_id,
                    ci.city AS city,
                    cc.state AS state_id,
                    st.state AS state,
                    cc.country AS country_id,
                    ctry.country AS country,
                    cc.pin,
                    cc.primary_contact AS contact_id,
                    CONCAT(ct.f_name,' ',ct.l_name) AS primary_contact,
                    cc.status AS status_id,
                    s.status AS status
                  FROM tbl_costcenter cc
                  LEFT JOIN tbl_costcentertype cct ON cc.cc_type = cct.id
                  LEFT JOIN tbl_entity ent ON cc.entity_id = ent.id
                  LEFT JOIN tbl_city ci ON cc.city = ci.id
                  LEFT JOIN tbl_state st ON cc.state = st.id
                  LEFT JOIN tbl_country ctry ON cc.country = ctry.id
                  LEFT JOIN tbl_contact ct ON cc.primary_contact = ct.id
                  LEFT JOIN tbl_status s ON cc.status = s.id
                  ORDER BY cc.cc_code ASC
                  LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getCostCentersCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_costcenter';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }


    public function getCostCenterById($id, $module, $username) {
        $query = "SELECT 
                    cc.id,
                    cc.cc_code,
                    cc.cc_type AS cc_type_id,
                    cct.cc_type AS cc_type,
                    cc.entity_id,
                    cc.incorp_date,
                    cc.gst_no,
                    cc.add1, cc.add2,
                    cc.city AS city_id,
                    ci.city AS city,
                    cc.state AS state_id,
                    st.state AS state,
                    cc.country AS country_id,
                    ctry.country AS country,
                    cc.pin,
                    cc.primary_contact AS contact_id,
                    CONCAT(ct.f_name,' ',ct.l_name) AS primary_contact,
                    cc.status AS status_id,
                    s.status AS status
                  FROM tbl_costcenter cc
                  LEFT JOIN tbl_costcentertype cct ON cc.cc_type = cct.id
                  LEFT JOIN tbl_city ci ON cc.city = ci.id
                  LEFT JOIN tbl_state st ON cc.state = st.id
                  LEFT JOIN tbl_country ctry ON cc.country = ctry.id
                  LEFT JOIN tbl_contact ct ON cc.primary_contact = ct.id
                  LEFT JOIN tbl_status s ON cc.status = s.code
                  WHERE cc.id = ?";
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function addCostCenter($cc_code, $cc_type, $entity_id, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $module, $username) {
    // SQL query for inserting a new cost center
        $query = "INSERT INTO tbl_costcenter 
                    (cc_code, cc_type, entity_id, incorp_date, gst_no, add1, add2, city, state, country, pin, primary_contact, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Log the query with parameters
        $this->logger->logQuery($query, [$cc_code, $cc_type, $entity_id, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $username], 'classes', $module, $username);
        
        // Execute the insert operation and get the inserted ID
        $insertId = $this->conn->insert($query, [$cc_code, $cc_type, $entity_id, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $username]);

        // Log the activity in the transaction log
        $activity = "New CostCenter added with ID: $insertId";
        $logQuery = 'INSERT INTO tbl_transaction_log (activity, action_user_id) VALUES (?, ?)';
        $this->logger->logQuery($logQuery, [$activity, $username], 'classes', $module, $username);
        $this->conn->insert($logQuery, [$activity, $username]);

        return $insertId;
    }


    public function editCostCenter($cc_code, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $id, $entity_id, $module, $username) {
        // SQL query for updating a cost center
        $query = "UPDATE tbl_costcenter SET 
                    cc_code = ?, entity_id = ?, incorp_date = ?, gst_no = ?, add1 = ?, add2 = ?, city = ?, state = ?, country = ?, pin = ?, primary_contact = ?, status = ?
                    WHERE id = ? AND entity_id = ?";

        // Log the query with parameters
        $this->logger->logQuery($query, [$cc_code, $entity_id, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $id, $entity_id], 'classes', $module, $username);

        // Execute the update operation and get the updated row count
        $updateId = $this->conn->update($query, [$cc_code, $entity_id, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $id, $entity_id]);

        // Log the activity in the transaction log
        $activity = "Updated CostCenter Code: " . $cc_code;
        $logQuery = 'INSERT INTO tbl_transaction_log (activity, action_user_id) VALUES (?, ?)';
        $this->logger->logQuery($logQuery, [$activity, $primary_contact], 'classes', $module, $username);
        $this->conn->insert($logQuery, [$activity, $primary_contact]);

        return $updateId;
    }

    public function getEntityIdFromCostCenter($costCenterId, $module, $username) {
        $query = 'SELECT entity_id FROM tbl_costcenter WHERE id = ?';
        $this->logger->logQuery($query, [$costCenterId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$costCenterId]);
        return $result ? (int)$result['entity_id'] : null;
    }


    public function deleteCostCenter($id, $module, $username) {
        $query = 'DELETE FROM tbl_costcenter WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->update($query, [$id]);
    }

    public function validateCCCode($cc_code, $excludeId = null) {
        if ($excludeId) {
            $query = 'SELECT cc_code FROM vw_costcenter_list WHERE cc_code = ? AND id <> ?';
            $params = [$cc_code, $excludeId];
        } else {
            $query = 'SELECT cc_code FROM vw_costcenter_list WHERE cc_code = ?';
            $params = [$cc_code];
        }
        $result = $this->conn->runQuery($query, $params);
        return empty($result);
    }
}
?>
