<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class CounterPartyInfo
{
    private $conn;
    private $logger;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllVendors($module, $username)
    {
        $query = "SELECT
                    v.id AS vendor_id,
                    r.reference_id,
                    v.vendor_code,
                    v.vendor_status AS status_id,
                    st.status AS status,
                    e.entity_name,

                    cp.full_registered_name,
                    cp.business_entity_type,
                    cp.reg_number,
                    cp.tan_number,
                    cp.trading_name,

                    cp.country_type,
                    cp.country_id,
                    cp.state_id,
                    c.country AS country_name,
                    s.state AS state_name,
                    cp.country_text,
                    cp.state_text,

                    cp.telephone,
                    cp.registered_address,
                    cp.business_address,

                    cp.contact_person_title,
                    cp.contact_person_name,
                    cp.contact_person_email,
                    cp.contact_person_mobile,

                    cp.accounts_person_title,
                    cp.accounts_person_name,
                    cp.accounts_person_contact_no,
                    cp.accounts_person_email

                FROM vms_vendor v

                JOIN vms_rfqs r
                    ON r.id = v.active_rfq

                JOIN vms_counterparty cp
                    ON cp.reference_id = r.reference_id
                LEFT JOIN tbl_country c ON cp.country_id = c.id
                LEFT JOIN tbl_state s ON cp.state_id = s.id
                LEFT JOIN tbl_entity e ON v.entity_id = e.id
                LEFT JOIN tbl_status st ON v.vendor_status = st.id";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getEntityIdByReference($referenceId, $module, $username)
    {
        $query = "SELECT entity_id FROM vms_rfqs WHERE reference_id = ?";
        $this->logger->logQuery($query, [$referenceId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$referenceId]);
        return $result['entity_id'] ?? null;
    }

    public function getVendorByReferenceId($reference_id, $module, $username)
    {
        $query = "SELECT
                    v.id AS vendor_id,
                    r.reference_id,
                    v.vendor_code,
                    v.vendor_status AS status_id,
                    st.status AS status,
                    e.entity_name,

                    cp.full_registered_name,
                    cp.business_entity_type,
                    cp.reg_number,
                    cp.tan_number,
                    cp.trading_name,

                    cp.country_type,
                    cp.country_id,
                    cp.state_id,
                    c.country AS country_name,
                    s.state AS state_name,
                    cp.country_text,
                    cp.state_text,

                    cp.telephone,
                    cp.registered_address,
                    cp.business_address,

                    cp.contact_person_title,
                    cp.contact_person_name,
                    cp.contact_person_email,
                    cp.contact_person_mobile,

                    cp.accounts_person_title,
                    cp.accounts_person_name,
                    cp.accounts_person_contact_no,
                    cp.accounts_person_email 
                FROM vms_vendor v 
                JOIN vms_rfqs r
                    ON r.id = v.active_rfq
                JOIN vms_counterparty cp
                    ON cp.reference_id = r.reference_id
                LEFT JOIN tbl_country c ON cp.country_id = c.id
                LEFT JOIN tbl_state s ON cp.state_id = s.id
                LEFT JOIN tbl_entity e ON v.entity_id = e.id
                LEFT JOIN tbl_status st ON v.vendor_status = st.id
                WHERE r.reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        var_dump($result);
        return $result;
    }

    // public function getReferenceIdf

    public function getPaginatedVendors($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        $query = "SELECT
                    v.id AS vendor_id,
                    r.reference_id,
                    v.vendor_code,
                    v.vendor_status AS status_id,
                    st.status AS status,
                    e.entity_name,

                    cp.full_registered_name,
                    cp.business_entity_type,
                    cp.reg_number,
                    cp.tan_status,
                    cp.tan_number,
                    cp.trading_name,

                    cp.country_type,
                    cp.country_id,
                    cp.state_id,
                    c.country AS country_name,
                    s.state AS state_name,
                    cp.country_text,
                    cp.state_text,

                    cp.telephone,
                    cp.registered_address,
                    cp.business_address,

                    cp.contact_person_title,
                    cp.contact_person_name,
                    cp.contact_person_email,
                    cp.contact_person_mobile,

                    cp.accounts_person_title,
                    cp.accounts_person_name,
                    cp.accounts_person_contact_no,
                    cp.accounts_person_email

                FROM vms_vendor v

                JOIN vms_rfqs r
                    ON r.id = v.active_rfq

                JOIN vms_counterparty cp
                    ON cp.reference_id = r.reference_id
                LEFT JOIN tbl_country c ON cp.country_id = c.id
                LEFT JOIN tbl_state s ON cp.state_id = s.id
                LEFT JOIN tbl_entity e ON v.entity_id = e.id
                LEFT JOIN tbl_status st ON v.vendor_status = st.id
                LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getCounterpartyByReferenceId($reference_id, $module, $username)
    {
        $query = "SELECT * FROM vms_counterparty where reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result;
    }

    public function getPaginatedCounterpartyDetails($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        $query = "SELECT * FROM vms_counterparty LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$limit, $offset]);
    }

    public function getVendorsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM vms_vendor';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    // insert into vms_counterparty and update rfq status in vms_rfqs
    public function insertCounterparty(
        $reference_id,
        $full_registered_name,
        $business_entity_type,
        $reg_number,
        $tan_status,
        $tan_number,
        $trading_name,
        $country_type,
        $country_id,
        $state_id,
        $country_text,
        $state_text,
        $telephone,
        $registered_address,
        $business_address,
        $contact_person_title,
        $contact_person_name,
        $contact_person_email,
        $contact_person_mobile,
        $accounts_person_title,
        $accounts_person_name,
        $accounts_person_contact_no,
        $accounts_person_email,
        $module,
        $username
    ) {

        // Check if reference exists in RFQs
        $entity_id = $this->getEntityIdByReference($reference_id, $module, $username);

        if (!$entity_id) {
            throw new Exception("Entity not found for reference ID: " . $reference_id);
        }

        // SQL query for inserting vendor
        $query = "INSERT INTO vms_counterparty (
            reference_id,
            full_registered_name,
            business_entity_type,
            reg_number,
            tan_status,
            tan_number,
            trading_name,
            country_type,
            country_id,
            state_id,
            country_text,
            state_text,
            telephone,
            registered_address,
            business_address,
            contact_person_title,
            contact_person_name,
            contact_person_email,
            contact_person_mobile,
            accounts_person_title,
            accounts_person_name,
            accounts_person_contact_no,
            accounts_person_email
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        // Params array without the fixed value `7` for `vendor_status`
        $params = [
            $reference_id,
            $full_registered_name,
            $business_entity_type,
            $reg_number,
            $tan_status,
            $tan_number,
            $trading_name,
            $country_type,
            $country_id,
            $state_id,
            $country_text,
            $state_text,
            $telephone,
            $registered_address,
            $business_address,
            $contact_person_title,
            $contact_person_name,
            $contact_person_email,
            $contact_person_mobile,
            $accounts_person_title,
            $accounts_person_name,
            $accounts_person_contact_no,
            $accounts_person_email
        ];

        // Log query for debugging
        $this->logger->logQuery($query, $params, 'classes', $module, $username);

        // Execute the insert operation
        $counterparty  =  $this->conn->insert($query, $params, 'Added Counterparty information');
        
        if($counterparty) {
            return $counterparty;
        } else {
            return false;
        }
    }

    // update counterparty info and no change in rfq status
    public function updateCounterparty(
        $reference_id,
        $full_registered_name,
        $business_entity_type,
        $reg_number,
        $tan_status,
        $tan_number,
        $trading_name,
        $country_type,
        $country_id,
        $state_id,
        $country_text,
        $state_text,
        $telephone,
        $registered_address,
        $business_address,
        $contact_person_title,
        $contact_person_name,
        $contact_person_email,
        $contact_person_mobile,
        $accounts_person_title,
        $accounts_person_name,
        $accounts_person_contact_no,
        $accounts_person_email,
        $module,
        $username,

    ) {

        $query = "UPDATE vms_counterparty SET 
                    full_registered_name = ?,
                    business_entity_type = ?,
                    reg_number = ?,
                    tan_status = ?,
                    tan_number = ?,
                    trading_name = ?,
                    country_type = ?,
                    country_id = ?,
                    state_id = ?,
                    country_text = ?,
                    state_text = ?,
                    telephone = ?,
                    registered_address = ?,
                    business_address = ?,
                    contact_person_title = ?,
                    contact_person_name = ?,
                    contact_person_email = ?,
                    contact_person_mobile = ?,
                    accounts_person_title = ?,
                    accounts_person_name = ?,
                    accounts_person_contact_no = ?,
                    accounts_person_email = ?
                    WHERE reference_id = ?";

        $params = [
            $full_registered_name,
            $business_entity_type,
            $reg_number,
            $tan_status,
            $tan_number,
            $trading_name,
            $country_type,
            $country_id,
            $state_id,
            $country_text,
            $state_text,
            $telephone,
            $registered_address,
            $business_address,
            $contact_person_title,
            $contact_person_name,
            $contact_person_email,
            $contact_person_mobile,
            $accounts_person_title,
            $accounts_person_name,
            $accounts_person_contact_no,
            $accounts_person_email,
            $reference_id
        ];

        // Log query
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        // Execute the update operation
        return $this->conn->update($query, $params, 'Counterparty information Updated');
    }


    public function deleteVendor($vendor_id, $module, $username)
    {
        $query = 'DELETE FROM vms_vendor WHERE id = ?';
        $this->logger->logQuery($query, [$vendor_id], 'classes', $module, $username);
        return $this->conn->update($query, [$vendor_id], 'Counterparty Deleted');
    }


    public function checkDuplicateByPAN($pan)
    {
        $query = 'SELECT 1 FROM vms_counterparty WHERE lower(trim(pan_number)) = lower(trim(?))';
        $this->logger->logQuery($query, [$pan], 'classes');
        return !empty($this->conn->runSingle($query, [$pan]));
    }


    public function checkEditDuplicateByPAN($pan, $vendor_id)
    {
        $query = 'SELECT 1 FROM vms_counterparty WHERE lower(trim(pan_number)) = lower(trim(?)) AND id != ?';
        $this->logger->logQuery($query, [$pan, $vendor_id], 'classes');
        return !empty($this->conn->runSingle($query, [$pan, $vendor_id]));
    }

    public function hasMultipleRfqsForUser($userId, $module, $username)
    {
        $query = "SELECT COUNT(*) AS rfq_count
                FROM vms_rfqs
                WHERE user_id = ?";
        $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$userId]);
        return isset($result['rfq_count']) && (int)$result['rfq_count'] > 1;
    }

    public function getVendorInfoByUserId($userId, $module, $username)
    {
        $query = "SELECT v.id AS vendor_id, r.reference_id as active_reference_id, r.expiry_date, v.vendor_code, v.vendor_status AS status_id, st.status AS status
                FROM vms_vendor v
                JOIN vms_rfqs r ON r.id = v.active_rfq
                LEFT JOIN tbl_status st ON v.vendor_status = st.id
                WHERE r.user_id = ?";
        $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$userId]);
        return $result;
    }

    public function getReferenceIdByUserId($userId, $module, $username)
    {
        try {
            $query = "SELECT v.reference_id
                FROM tbl_users u
                JOIN tbl_contact c ON u.contact_id = c.id
                JOIN vms_rfqs v ON u.id = v.user_id
                WHERE u.id = ?
                LIMIT 1";

            $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
            $result = $this->conn->runSingle($query, [$userId]);
            return $result['reference_id'] ?? null;
        } catch (Exception $e) {
            $this->logger->log("Error fetching reference_id for userId $userId: " . $e->getMessage());
            return null;
        }
    }

    public function getAllReferenceIdsByUserId($userId, $module, $username)
    {
        try {
            $query = "SELECT v.reference_id
                FROM tbl_users u
                JOIN tbl_contact c ON u.contact_id = c.id
                JOIN vms_rfqs v ON u.id = v.user_id
                WHERE u.id = ?";

            $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
            $results = $this->conn->runQuery($query, [$userId]);
            return array_column($results, 'reference_id');
        } catch (Exception $e) {
            $this->logger->log("Error fetching reference_ids for userId $userId: " . $e->getMessage());
            return [];
        }
    }

    public function getVendorIdByReference($referenceId)
    {
        try {
            $query = "SELECT vendor_id FROM vms_rfqs WHERE reference_id = ? AND vendor_id IS NOT NULL";

            $this->logger->logQuery($query, [$referenceId], 'classes');
            $result = $this->conn->runSingle($query, [$referenceId]);
            return $result['vendor_id'] ?? null;
            
        } catch (Exception $e) {
            $this->logger->log("Error fetching vendor_id for Reference ID $referenceId: " . $e->getMessage());
            return null;
        }
    }

    // get vendor id by vendor code
    public function getVendorIdByVendorCode($vendorCode, $module, $username)
    {
        $query = 'SELECT id FROM vms_vendor WHERE vendor_code = ?';
        $this->logger->logQuery($query, [$vendorCode], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendorCode]);
        return $result['id'] ?? null;
    }

    // Financial Year in format YY-YY
    public function getCurrentFinancialYear()
    {
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('m');

        if ($currentMonth >= 4) {
            $startYear = $currentYear;
            $endYear = $currentYear + 1;
        } else {
            $startYear = $currentYear - 1;
            $endYear = $currentYear;
        }

        return substr($startYear, -2) . '-' . substr($endYear, -2);
    }

    public function getStateCodeByStateId($stateId, $module, $username)
    {
        $query = 'SELECT code FROM tbl_state WHERE id = ?';
        $this->logger->logQuery($query, [$stateId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$stateId]);
        return $result['code'] ?? null;
    }

    public function getStateByReferenceId($referenceId, $module, $username)
    {
        // get state (tbl_state linked via state_id) from vms_counterparty if its india else get state_text
        $query = 'SELECT st.code FROM vms_counterparty v JOIN tbl_state st ON v.state_id = st.id WHERE v.reference_id = ?';
        $this->logger->logQuery($query, [$referenceId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$referenceId]);
        $stateId = $result['code'] ?? null;
        if ($stateId !== null) {
            return $stateId;
        } else {
            $query = 'SELECT state_text FROM vms_counterparty WHERE reference_id = ?';
            $this->logger->logQuery($query, [$referenceId], 'classes', $module, $username);
            $result = $this->conn->runSingle($query, [$referenceId]);
            return $result['state_text'] ?? null;
        }
    }

    public function getEntityCodeByEntityId($entityId, $module, $username)
    {
        $query = 'SELECT cc_code FROM tbl_costcenter WHERE entity_id = ?';
        $this->logger->logQuery($query, [$entityId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$entityId]);
        return $result['cc_code'] ?? null;
    }

    public function getEntityByReferenceId($referenceId, $module, $username)
    {
        $query = 'SELECT entity_id FROM vms_rfqs WHERE reference_id = ?';
        $this->logger->logQuery($query, [$referenceId], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$referenceId]);
        $entityId = $result['entity_id'] ?? null;
        if ($entityId !== null) {
            return $this->getEntityCodeByEntityId($entityId, $module, $username);
        } else {
            return null;
        }
    }

    // Vendor Code Format : <EntityCode>-<StateCode>-<FinancialYear>-<4DigitSerialNo>
    public function generateVendorCode($referenceId, $module, $username)
    {
        // $entityCode = $this->getEntityByReferenceId($referenceId, $module, $username);
        $vendorCodePrefix = "VNDR";
        $stateCode = $this->getStateByReferenceId($referenceId, $module, $username);
        $financialYear = $this->getCurrentFinancialYear();

        if (!$vendorCodePrefix || !$stateCode) {
            $this->logger->log("Cannot generate Vendor Code: Missing Entity Code or State Code for Reference ID $referenceId");
            return null;
        }

        $prefix = "{$vendorCodePrefix}/{$stateCode}/{$financialYear}/";

        $query = "SELECT COUNT(*) AS count FROM vms_vendor WHERE vendor_code LIKE ?";
        $likePattern = $prefix . '%';
        $this->logger->logQuery($query, [$likePattern], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$likePattern]);
        $count = isset($result['count']) ? (int)$result['count'] : 0;

        $serialNumber = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        return $prefix . $serialNumber;
    }
}
