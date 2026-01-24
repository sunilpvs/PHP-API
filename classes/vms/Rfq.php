<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/GraphAutoMailer.php';

class Rfq
{
    private $conn;
    private $logger;
    private $vendorLoginUrl;
    private $yourEmail;


    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
        $this->vendorLoginUrl = $config['vendor_login_url'];
        $this->yourEmail = $config['app_email'];
    }

    public function getAllRfqs($module, $username)
    {
        $query = 'SELECT * FROM vms_rfqs';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }


    public function getPaginatedRfqs($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        // reference id, entity name, vendor name, contact name, email, mobile, status - display in rfq list interface
        $query = "SELECT 
            r.id,
            r.reference_id,
            r.vendor_name,
            r.contact_name,
            r.email, 
            r.mobile,
            r.entity_id,
            ent.entity_name AS entity,
            r.status,
            stat.status AS status_name,
            r.email_sent,
            r.created_by,
            r.created_datetime,
            r.last_updated,
            r.last_updated_datetime
          FROM vms_rfqs r
          LEFT JOIN tbl_entity ent ON r.entity_id = ent.id
          LEFT JOIN tbl_status stat ON r.status = stat.id
          LIMIT $limit OFFSET $offset";

        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getRfqsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM vms_rfqs';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getRfqById($id, $module, $username)
    {
        $query = "SELECT 
            r.id,
            r.reference_id,
            r.vendor_name,
            r.contact_name,
            r.email,
            r.mobile,
            r.entity_id,
            ent.entity_name AS entity,
            r.status,
            stat.status AS status_name,
            r.email_sent,
            r.created_by,
            r.created_datetime,
            r.last_updated,
            r.last_updated_datetime
          FROM vms_rfqs r
          LEFT JOIN tbl_entity ent ON r.entity_id = ent.id
          LEFT JOIN tbl_status stat ON r.status = stat.id";

        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function insertRfq($vendor_name, $contact_name, $email, $mobile, $entity_id, $created_by, $module, $username)
    {
        //Generating Reference ID
        $reference_id = $this->generateReferenceId();
        // Inserting RFQ data.
        $query = "INSERT INTO vms_rfqs (reference_id, vendor_name, contact_name, email, mobile, entity_id, status, email_sent, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, 7, false, ?)";
        $params = [$reference_id, $vendor_name, $contact_name, $email, $mobile, $entity_id, $created_by];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'RFQ Inserted';
        $insertId = $this->conn->insert($query, $params, $logMessage);

        //Create Vendaor Contact using Name, Email and Mobile
        //insert into tbl_contact  contact type = vendor , name = name, email, mobile
        $query = "INSERT INTO tbl_contact (f_name, l_name, email, personal_email, city, state, country, emp_status, department, designation, mobile, contacttype_id, entity_id, createdBy) 
                    VALUES (?, ?, ?, ?, 1, 1, 1, 1, 6, 14, ?, 5, ?, ?)";
        $params = [$contact_name, '', $email, $email, $mobile,  $entity_id, $username];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $vendorContactId = $this->conn->insert($query, $params, 'Vendor contact created');


        //Create user based on Vendor email.
        $randomPassword = bin2hex(random_bytes(4)); // 8 character random password
        $query = 'INSERT INTO tbl_users(user_name, email, password, user_status, contact_id, status, entity_id, createdBy)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $hashedPassword = password_hash($randomPassword, PASSWORD_BCRYPT);
        $params = [$email, $email, $hashedPassword, 1, $vendorContactId, 'verified', $entity_id, $created_by];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $userId = $this->conn->insert($query, $params, 'Vendor user created');

        //Insert into tbl_user_modules
        $query = 'INSERT INTO tbl_user_modules (user_id, email, module_id, user_role_id, enabled) 
                    VALUES (?, ?, ?, ?, ?)';
        $params = [$userId, $email, 4, 8, 1]; // Assuming module_id 4 is for VMS and user_role_id 8 is for VMS_VENDOR
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $moduleId = $this->conn->insert($query, $params, 'Vendor user module access created');

        //Sending Email notification to Vendor with login details.

        $mailer = new AutoMail();

        // Create the key-value array for the email body
        $keyValueData = [
            "Message" => "Your company vendor registration process has been initiated. Here are your login details.",
            "Vendor Name" => $vendor_name,
            "Contact Person" => "IT",
            "Your EMail" => $this->yourEmail,
            "Link" => $this->vendorLoginUrl,
            "Login Details" => "
                Username: $email
                Password: $randomPassword"
        ];

        // Prepare email data and send email using the mailer
        $emailSent = $mailer->sendEmail(
            to: [$email],
            subject: 'Vendor Registration Details',
            keyValueArray: $keyValueData,
            cc: ['sunil.pvs@pvs-consultancy.com', 'ramalakshmi@pvs-consultancy.com', 'bhaskar.teja@pvs-consultancy.com'],
            bcc: ['team.pvs@pvs-consultancy.com'],
            attachments: []
        );

        if ($emailSent) {
            // Update email_sent flag in vms_rfqs table
            $updateQuery = 'UPDATE vms_rfqs SET email_sent = true WHERE id = ?';
            $this->logger->logQuery($updateQuery, [$insertId], 'classes', $module, $username);
            $this->conn->update($updateQuery, [$insertId], 'RFQ email_sent updated');
        }

        if ($insertId && $userId && $moduleId && $vendorContactId && $emailSent) {
            return true;
        } else {
            return false;
        }
    }

    // re-initiate RFQ if vendor is expired after initial submission
    public function reinitiateRfq($id, $last_updated, $module, $username) {}

    // Not needed as of now 

    public function updateRfq($id, $vendor_name, $contact_name, $email, $mobile, $entity_id, $status, $expiry_date, $last_updated, $module, $username)
    {
        $query = 'UPDATE vms_rfqs SET vendor_name = ?, contact_name = ?, email = ?, mobile = ?, entity_id = ?, status = ?, expiry_date = ?, last_updated = ?, last_updated_datetime = NOW() WHERE id = ?';
        $params = [$vendor_name, $contact_name, $email, $mobile, $entity_id, $status, $expiry_date, $last_updated, $id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'RFQ Updated';
        return $this->conn->update($query, $params, $logMessage);
    }

    // Not needed as of now

    public function deleteRfq($id, $module, $username)
    {
        $query = 'DELETE FROM vms_rfqs WHERE id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $logMessage = 'RFQ Deleted';
        return $this->conn->update($query, [$id], $logMessage);
    }


    public function checkDuplicateRfq($vendor_name, $email, $mobile)
    {
        $query = 'SELECT 1 FROM vms_rfqs WHERE lower(trim(vendor_name)) = lower(trim(?)) OR email = ? OR mobile = ?';
        $this->logger->logQuery($query, [$vendor_name, $email, $mobile], 'classes');
        $duplicate = $this->conn->runSingle($query, [$vendor_name, $email, $mobile]);
        return !empty($duplicate);
    }

    public function checkEditDuplicateRfq($vendor_name, $mobile, $id)
    {
        $query = 'SELECT 1 FROM vms_rfqs WHERE lower(trim(vendor_name)) = lower(trim(?)) AND mobile = ? AND id != ?';
        $this->logger->logQuery($query, [$vendor_name, $mobile, $id], 'classes');
        $duplicate = $this->conn->runSingle($query, [$vendor_name, $mobile, $id]);
        return !empty($duplicate);
    }

    // ✅ Vendor Combo
    // public function getVendorCombo($module, $username) {
    //     $fields = ['id', 'reference_id', 'vendor_name', 'email'];
    //     $query = $this->conn->buildSelectQuery('vms_rfqs', $fields, $fields, 'vendor_name ASC');
    //     if (!$query) {
    //         $this->logger->log("Failed to build vendor combo query", 'classes', $module, $username);
    //         return [];
    //     }
    //     $this->logger->logQuery($query, [], 'classes', $module, $username);
    //     return $this->conn->runQuery($query);
    // }

    public function getAllRfqsByUserId($userId, $module, $username)
    {
        $query = 'SELECT r.id, r.reference_id, r.status AS status_id, s.status AS status, r.vendor_name, te.entity_name, r.expiry_date,
                    cp.full_registered_name, cp.contact_person_name, cp.contact_person_email, cp.contact_person_mobile,
                    cp.accounts_person_name, cp.accounts_person_email, cp.accounts_person_contact_no
                    FROM vms_rfqs r
                    JOIN tbl_status s ON r.status = s.id
                    JOIN tbl_entity te ON r.entity_id = te.id
                    LEFT JOIN vms_counterparty cp ON r.reference_id = cp.reference_id
                    WHERE user_id = ? ORDER BY vendor_name ASC';

        $this->logger->logQuery($query, [$userId], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$userId]);
    }

    public function getPendingSubmittedRfqs($module, $username)
    {
        $query = 'SELECT id, reference_id, vendor_name FROM vms_rfqs WHERE status IN (8, 9) ORDER BY vendor_name ASC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getAllSubmittedRfqs($module, $username)
    {
        $query = 'SELECT id, reference_id, vendor_name FROM vms_rfqs WHERE status IN (8,9,10,11,12,13,14) ORDER BY vendor_name ASC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getAllVendors($offset, $limit, $module, $username)
    {
        $query = "SELECT
                    r.reference_id,
                    r.expiry_date,

                    v.id AS vendor_id,
                    v.vendor_code,
                    v.vendor_status AS status_id,

                    CASE
                        WHEN v.vendor_status = 13 THEN 'Blocked'
                        WHEN v.vendor_status = 14 THEN 'Suspended'
                        WHEN v.active_rfq IS NOT NULL AND r.expiry_date >= CURDATE() THEN 'Active'
                        -- WHEN v.active_rfq IS NULL AND EXISTS (
						-- 	SELECT 1 FROM vms_rfqs r2 
						-- 	    WHERE r2.vendor_id = v.id
                        --         AND r2.status IN (7,8,9,10) )
                        --     THEN 'Reinitiated'
                        WHEN v.vendor_status = 15 THEN 'Expired'
                        ELSE 'Unknown'
                    END AS status,

                    e.entity_name,

                    cp.full_registered_name,
                    cp.trading_name,
                    cp.registered_address,
                    cp.business_address,
                    cp.business_entity_type,
                    cp.reg_number,

                    CASE
                        WHEN cp.country_id IS NULL THEN cp.country_text
                        ELSE c.country
                    END AS country,

                    CASE
                        WHEN cp.state_id IS NULL THEN cp.state_text
                        ELSE st.state
                    END AS state,

                    cp.telephone,

                    CONCAT(cp.contact_person_title, ' ', cp.contact_person_name) AS contact_person_name,
                    cp.contact_person_mobile,
                    cp.contact_person_email,

                    CONCAT(cp.accounts_person_title, ' ', cp.accounts_person_name) AS accounts_person_name,
                    cp.accounts_person_contact_no AS accounts_person_mobile,
                    cp.accounts_person_email

                FROM vms_vendor v

                LEFT JOIN vms_rfqs r
                    ON r.id = v.active_rfq

                LEFT JOIN vms_counterparty cp
                    ON cp.reference_id = r.reference_id

                LEFT JOIN tbl_country c
                    ON cp.country_id = c.id

                LEFT JOIN tbl_state st
                    ON cp.state_id = st.id

                JOIN tbl_entity e
                    ON v.entity_id = e.id

                WHERE v.vendor_status IN (11, 13, 14, 15)

                LIMIT $limit OFFSET $offset";

        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    // ✅ Get Vendor Details by ID
    public function getVendorById($id, $module, $username)
    {
        $query = "SELECT 
            id, reference_id, vendor_name, contact_name, email, mobile, entity_id, status, created_datetime
        FROM vms_rfqs WHERE id = ?";
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    public function getEmailByReferenceId($reference_id, $module, $username)
    {
        $query = "SELECT email FROM vms_rfqs WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result ? $result['email'] : null;
    }

//     -- Table structure for table `vms_rfqs`
//   CREATE TABLE vms_rfqs (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     reference_id VARCHAR(20) NOT NULL UNIQUE, -- acts as temporary vendor ID RFIVEN-001
//     vendor_id INT(11) DEFAULT NULL,            -- filled after approval. First time Vendor it will be blank , for renewals etc this will be taken from Vendor Screen.
//     vendor_name VARCHAR(100),
//     contact_name VARCHAR(100),
//     email VARCHAR(100),
//     mobile VARCHAR(15) NOT NULL,
//     entity_id INT DEFAULT NULL,
//     status INT DEFAULT NULL,
//     email_sent BOOLEAN DEFAULT FALSE,
//     submission_count INT DEFAULT 1,
//     is_active BOOLEAN DEFAULT FALSE,
// 	expiry_date DATETIME DEFAULT NULL,
//     created_by INT NOT NULL,
//     created_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
//     last_updated INT DEFAULT NULL,
//     last_updated_datetime DATETIME DEFAULT NULL
    
// );
// -- --------------------------------------------------------
// -- Table structure for table `vms_vendor`
// CREATE TABLE vms_vendor (
// 	id INT AUTO_INCREMENT PRIMARY KEY,
//     active_rfq INT(11),  -- link back to RFQ
//     vendor_code varchar(50) DEFAULT NULL UNIQUE, -- Created and updated for first time RFQ approval.
//     vendor_status INT(11), -- Active, Expired, Suspended, Blocked
//     entity_id INT(11), 
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
// );

    // ✅ Get Vendor Email by Vendor Code based on above table structures
    public function getEmailByVendorCode($vendor_code, $module, $username)
    {
        $query = "SELECT r.email FROM vms_rfqs r JOIN vms_vendor v ON r.vendor_id = v.id WHERE v.vendor_code = ? LIMIT 1;";
        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendor_code]);
        return $result ? $result['email'] : null;
    }

    // ✅ Review Vendor (Approve / Reject)
    public function reviewVendor($id, $status, $module, $username)
    {
        $query = "UPDATE vms_rfqs 
                SET status = ?, last_updated = ?, last_updated_datetime = NOW() 
                WHERE id = ?";
        $params = [$status, $username, $id];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);

        $result = $this->conn->update($query, $params, "Vendor $status");

        // Get email for notification
        $vendor = $this->getVendorById($id, $module, $username);
        if ($vendor && !empty($vendor['email'])) {
            $this->sendEmail(
                $vendor['email'],
                "Vendor $status Notification",
                "Hello {$vendor['vendor_name']},<br>Your vendor registration has been <b>$status</b>.<br><br>Regards,<br>Admin Team"
            );
        }

        return $result;
    }

    public function getVendorDetailsByRfqId($id, $module, $username)
    {
        $query = "SELECT 
                    r.id,
                    r.reference_id,
                    r.vendor_name,
                    r.contact_name,
                    r.email,
                    r.mobile,
                    r.entity_id,
                    ent.entity_name AS entity,
                    r.status,
                    stat.status AS status_name,
                    r.email_sent,
                    r.created_by,
                    r.created_datetime,
                    r.last_updated,
                    r.last_updated_datetime,
                    -- Vendor Profile Fields from vms_vendor
                    v.full_registered_name,
                    v.business_entity_type,
                    v.trading_name,
                    v.company_email,
                    v.telephone,
                    v.registered_address,
                    v.business_address,
                    v.contact_person_details,
                    v.website,
                    v.country_of_incorporation,
                    v.trade_license_number,
                    v.cin_number,
                    v.pan_number,
                    v.tan_number,
                    v.gst_vat_number,
                    v.accounts_manager_name,
                    v.accounts_manager_contact_no,
                    v.accounts_manager_email,
                    v.msme_registered,
                    v.msme_number,
                    v.msme_category,
                    v.bank_name,
                    v.account_number,
                    v.ifsc_code,
                    v.trade_license_document,
                    v.certificate_document,
                    v.declaration_text
                FROM vms_rfqs r
                LEFT JOIN tbl_entity ent ON r.entity_id = ent.id
                LEFT JOIN tbl_status stat ON r.status = stat.id
                LEFT JOIN vms_vendor v ON r.reference_id = v.reference_id
                WHERE r.id = ?";

        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$id]);
    }

    // ✅ Send Email
    private function sendEmail($to, $subject, $message)
    {
        $headers  = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: noreply@yourdomain.com" . "\r\n";
        return mail($to, $subject, $message, $headers);
    }

    public function generateReferenceId()
    {
        $latestId = $this->conn->runSingle("SELECT MAX(id) as max_id FROM vms_rfqs")['max_id'] ?? 0;
        $newId = $latestId + 1;
        return 'RFI-VEN-' . str_pad($newId, 5, '0', STR_PAD_LEFT);
    }

    public function isFormSubmittedPreviously($reference_id, $module, $username)
    {
        $query = "SELECT submission_count FROM vms_rfqs WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        if (!empty($result) && isset($result['submission_count']) && $result['submission_count'] > 1) {
            return true; // resubmission
        }

        return false; // first submission
    }

    public function incrementSubmissionCount($reference_id, $module, $username)
    {
        $query = "UPDATE vms_rfqs SET submission_count = submission_count + 1 WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->update($query, [$reference_id], 'Incremented submission count');
    }

    public function getActiveRfqIdByReferenceId($reference_id, $module, $username)
    {
        $query = 'SELECT active_rfq FROM vms_vendor WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result ? $result['active_rfq'] : null;
    }

    public function getRfqIdByReferenceId($reference_id, $module, $username)
    {
        $query = 'SELECT id FROM vms_rfqs WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result ? $result['id'] : null;
    }

    public function getEntityIdByReferenceId($reference_id, $module, $username)
    {
        $query = 'SELECT entity_id FROM vms_rfqs WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result ? $result['entity_id'] : null;
    }

    // for vms module - get all rfqs by vendor code (vendor specific rfq list)
    // also for vendor dashboard - to show their rfq list specific to them
    public function getAllRfqsByVendor($vendor_code, $module, $username)
    {
        $query = "SELECT
                    r.reference_id,
                    r.expiry_date,
                    r.email,
                    r.mobile,

                    v.id AS vendor_id,
                    v.vendor_code,
                    r.status AS status_id,

                    s.status AS status,

                    e.entity_name,

                    cp.full_registered_name,
                    cp.trading_name,
                    cp.registered_address,
                    cp.business_address,
                    cp.business_entity_type,
                    cp.reg_number,

                    CASE
                        WHEN cp.country_id IS NULL THEN cp.country_text
                        ELSE c.country
                    END AS country,

                    CASE
                        WHEN cp.state_id IS NULL THEN cp.state_text
                        ELSE st.state
                    END AS state,

                    cp.telephone,

                    CONCAT(cp.contact_person_title, ' ', cp.contact_person_name) AS contact_person_name,
                    cp.contact_person_mobile,
                    cp.contact_person_email,

                    CONCAT(cp.accounts_person_title, ' ', cp.accounts_person_name) AS accounts_person_name,
                    cp.accounts_person_contact_no AS accounts_person_mobile,
                    cp.accounts_person_email

                FROM vms_vendor v

                LEFT JOIN vms_rfqs r
                    ON r.vendor_id = v.id

                LEFT JOIN vms_counterparty cp
                    ON cp.reference_id = r.reference_id

                LEFT JOIN tbl_country c
                    ON cp.country_id = c.id

                LEFT JOIN tbl_state st
                    ON cp.state_id = st.id

                LEFT JOIN tbl_status s
                    ON r.status = s.id

                JOIN tbl_entity e
                    ON v.entity_id = e.id
                WHERE v.vendor_code IS NOT NULL AND 
                v.vendor_code = ?";

        // $query = 'SELECT * FROM vms_rfqs WHERE vendor_id = ?';
        $this->logger->logQuery($query, [$vendor_code], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$vendor_code]);
    }


    public function isExistingVendor($reference_id, $module, $username)
    {
        $query = 'SELECT vendor_id FROM vms_rfqs WHERE reference_id = ? AND vendor_id IS NOT NULL';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        if($result['vendor_id'] !== null && !empty($result['vendor_id'])) {
            return true;
        }
        return false;
    }

    public function getVendorIdByReferenceId($reference_id, $module, $username){
        $query = 'SELECT vendor_id FROM vms_rfqs WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result ? $result['vendor_id'] : null;
    }

    public function getVendorCodeByVendorId($vendor_id, $module, $username){
        $query = 'SELECT vendor_code FROM vms_vendor WHERE id = ?';
        $this->logger->logQuery($query, [$vendor_id], 'classes', $module, $username);
        $result = $this->conn->runSingle($query, [$vendor_id]);
        return $result ? $result['vendor_code'] : null;
    }

    public function getExpiredRfqsByDate(){
        $query = 'SELECT id, reference_id FROM vms_rfqs WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status = 11';
        $this->logger->logQuery($query, [], 'classes');
        $result = $this->conn->runQuery($query);
        return $result;
    }

    public function getExpiredRfqs(){
        $query = 'SELECT r.id, v.vendor_code, v.vendor_id, r.user_id, r.reference_id FROM vms_rfqs r 
                    JOIN vms_vendor v ON r.vendor_id = v.id
                    WHERE status = 15';
        $this->logger->logQuery($query, [], 'classes');
        $result = $this->conn->runQuery($query);
        return $result;
    }

    public function hasOtherActiveRfqs($vendor_id, $module){
        $query = 'SELECT 1 FROM vms_rfqs WHERE vendor_id = ? AND status = 11 LIMIT 1';
        $this->logger->logQuery($query, [$vendor_id], 'classes', $module);
        $result = $this->conn->runSingle($query, [$vendor_id]);
        return $result ? true : false;
    }

    public function getActiveRfqForVendor($vendor_id, $module){
        $query = 'SELECT r.id, r.reference_id, r.email, v.vendor_code FROM vms_rfqs r 
                    JOIN vms_vendor v ON r.vendor_id = v.id 
                    WHERE r.vendor_id = ? AND r.status = 11 LIMIT 1';
        $this->logger->logQuery($query, [$vendor_id], 'classes', $module);
        $result = $this->conn->runSingle($query, [$vendor_id]);
        return $result ? $result : null;
    }

    public function getVmsAdminEmails($module, $username)
    {
        $query = "SELECT email FROM tbl_user_modules WHERE user_role_id = 6;";
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $results = $this->conn->runQuery($query);
        $emails = [];
        foreach ($results as $row) {
            $emails[] = $row['email'];
        }
        return $emails;
    }

    public function getRfqsByDays($days){
        $query = 'SELECT id, reference_id, vendor_id, contact_name, email, mobile, entity_id, status, created_datetime 
                    FROM vms_rfqs 
                    WHERE DATEDIFF(expiry_date, CURDATE()) = ?';
        $this->logger->logQuery($query, [$days], 'classes');
        $result = $this->conn->runQuery($query, [$days]);
        return $result;
    }

    public function getSubmittedRfqsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM vms_rfqs WHERE status IN (8)';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getActiveVendorsCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM vms_vendor WHERE vendor_status = 11';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }
}
