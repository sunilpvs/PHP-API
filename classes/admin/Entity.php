<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Entity {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllEntities($module, $username) {
        $query = 'SELECT 
                        e.id,
                        e.entity_name,
                        e.cin,
                        e.incorp_date,
                        c.city,
                        d.state, 
                        e.status AS status_id,
                        s.status AS status
                  FROM tbl_entity e
                    LEFT JOIN tbl_costcenter b ON b.entity_id = e.id AND b.cc_type = 1
                    LEFT JOIN tbl_city c ON b.city = c.id
                    LEFT JOIN tbl_state d ON b.state = d.id
                    LEFT JOIN tbl_status s ON e.status = s.id';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getPaginatedEntities($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT 
                        e.id,
                        e.entity_name,
                        e.cin,
                        e.incorp_date,
                        c.city,
                        d.state, 
                        e.status AS status_id,
                        s.status AS status
                  FROM tbl_entity e
                    LEFT JOIN tbl_costcenter b ON b.entity_id = e.id AND b.cc_type = 1
                    LEFT JOIN tbl_city c ON b.city = c.id
                    LEFT JOIN tbl_state d ON b.state = d.id
                    LEFT JOIN tbl_status s ON e.status = s.id
                    ORDER BY e.entity_name ASC
                    LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getEntitiesCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM tbl_entity';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getEntityById($id, $module, $username) {
        $query = 'SELECT 
                        e.id,
                        e.entity_name,
                        e.cin,
                        e.incorp_date,
                        e.status AS status_id,
                        s.status AS status
                  FROM tbl_entity e
                  LEFT JOIN tbl_status s ON e.status = s.code
                  WHERE e.id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function addEntity($entity_name, $cc_code, $cin, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact,  $status, $module, $username) {
        $query = 'INSERT INTO tbl_entity (entity_name, cin, incorp_date, status) VALUES (?, ?, ?, ?)';
        $this->logger->logQuery($query, [$entity_name, $cin, $incorp_date, $status], 'classes', $module, $username);
        $logMessage = 'Entity Inserted ';
        $entityId =  $this->conn->insert($query, [$entity_name, $cin, $incorp_date, $status], $logMessage);

        $cc_type=1; // Head-Office


        // Entry into CostCenter- Head-Office Data for newly created Entity
        $query = "INSERT INTO tbl_costcenter(cc_code, cc_type, entity_id, incorp_date, gst_no, add1, add2, city, state, country, pin, primary_contact, status, created_by)
                    VALUES (?, $cc_type, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $this->logger->logQuery($query, ['HO', 'Head-Office', $entityId, $incorp_date, '', '', '', '', '', '', '', null, $status, $username], 'classes', $module, $username);
        $InsertId = $this->conn->insert($query, [$cc_code, $entityId, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $status, $username], 'Cost Center Head Office created for new Entity');
        
        if($InsertId && $entityId){
            return true;
        }
        return false;
    }

    public function updateEntity($entity_name, $cin, $incorp_date, $status, $id, $module, $username) {
        $query = 'UPDATE tbl_entity SET entity_name = ?, cin = ?, incorp_date = ?, status = ? WHERE id = ?';
        $this->logger->logQuery($query, [$entity_name, $cin, $incorp_date, $status, $id], 'classes', $module, $username);
        $logMessage = 'Entity Updated ';
        return $this->conn->update($query, [$entity_name, $cin, $incorp_date, $status, $id], $logMessage);
    }

    public function deleteEntity($id, $module, $username) {
        $query = 'DELETE FROM tbl_entity WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'Entity Deleted ';
        return $this->conn->update($query, [$id], $logMessage);
    }

    public function getPrimaryContacts($module, $username){
        $query = "SELECT id, concat(f_name,' ',l_name) as employee FROM tbl_contact WHERE emp_status = 1 AND contacttype_Id IN (2,3) ORDER BY id";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function checkDuplicateEntityName($entity_name) {
        $query = 'SELECT 1 FROM tbl_entity WHERE lower(trim(entity_name)) = lower(trim(?))';
        $this->logger->logQuery($query, [$entity_name], 'classes');
        $duplicate = $this->conn->runSingle($query, [$entity_name]);
        return !empty($duplicate);
    }

    public function checkEditDuplicateEntityName($entity_name, $id) {
        $query = 'SELECT 1 FROM tbl_entity WHERE lower(trim(entity_name)) = lower(trim(?)) AND id != ?';
        $this->logger->logQuery($query, [$entity_name, $id], 'classes');
        $duplicate = $this->conn->runSingle($query, [$entity_name, $id]);
        return !empty($duplicate);
    }

    public function checkDuplicateCin($cin) {
        $query = 'SELECT 1 FROM tbl_entity WHERE lower(trim(cin)) = lower(trim(?))';
        $this->logger->logQuery($query, [$cin], 'classes');
        $duplicate = $this->conn->runSingle($query, [$cin]);
        return !empty($duplicate);
    }

    public function checkEditDuplicateCin($cin, $id) {
        $query = 'SELECT 1 FROM tbl_entity WHERE lower(trim(cin)) = lower(trim(?)) AND id != ?';
        $this->logger->logQuery($query, [$cin, $id], 'classes');
        $duplicate = $this->conn->runSingle($query, [$cin, $id]);
        return !empty($duplicate);
    }
}
?>
