<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Bank {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllBankAccounts($module, $username) {
        $query = 'SELECT * FROM vms_bank_accounts';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getBankDetailsByReference($reference_id, $module, $username) {
        $query = 'SELECT * FROM vms_bank_accounts WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$reference_id]);
    }
   
    public function getBankAccountsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM vms_bank_accounts';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    

    public function insertBankAccount(
        $reference_id, 
        $account_holder_name,
        $bank_name,
        $bank_address,
        $transaction_type,
        $country_type,
        $country_id,
        $country_text,
        $account_number,
        $ifsc_code,
        $swift_code,
        $beneficiary_name,
        $module, 
        $username
    ) {

        $query = 'INSERT INTO vms_bank_accounts (
            reference_id, 
            account_holder_name, 
            bank_name, 
            bank_address, 
            transaction_type,
            country_type,
            country_id,
            country_text,
            account_number, 
            ifsc_code, 
            swift_code, 
            beneficiary_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $params = [
            $reference_id, 
            $account_holder_name, 
            $bank_name, 
            $bank_address, 
            $transaction_type,
            $country_type,
            $country_id,
            $country_text, 
            $account_number,
            $ifsc_code, 
            $swift_code, 
            $beneficiary_name
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Bank account inserted';

        return $this->conn->insert($query, $params, $logMessage);
    }

    public function updateBankAccount(
        $reference_id, 
        $account_holder_name, 
        $bank_name, 
        $bank_address, 
        $transaction_type,
        $country_type,
        $country_id,    
        $country_text,
        $account_number, 
        $ifsc_code, 
        $swift_code, 
        $beneficiary_name, 
        $module, 
        $username
    ) {
        $query = 'UPDATE vms_bank_accounts SET  
            account_holder_name = ?, 
            bank_name = ?, 
            bank_address = ?, 
            transaction_type = ?, 
            country_type = ?, 
            country_id = ?,
            country_text = ?, 
            account_number = ?, 
            ifsc_code = ?, 
            swift_code = ?, 
            beneficiary_name = ?
            WHERE reference_id = ?';

        $params = [
            $account_holder_name, 
            $bank_name, 
            $bank_address, 
            $transaction_type,
            $country_type,
            $country_id,
            $country_text,
            $account_number, 
            $ifsc_code, 
            $swift_code, 
            $beneficiary_name, 
            $reference_id
        ];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Bank account updated';

        return $this->conn->update($query, $params, $logMessage);
    }


    public function deleteBankAccount($reference_id, $module, $username) {
        $query = 'DELETE FROM vms_bank_accounts WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $logMessage = 'Bank account deleted';
        return $this->conn->update($query, [$reference_id], $logMessage);
    }

    public function duplicateBankRecordCheck($reference_id) {
        $query = "SELECT COUNT(*) as count FROM vms_bank_accounts WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes');
        $result = $this->conn->runSingle($query, [$reference_id]);
        return $result['count'] > 0;
    }

    // public function checkEditDuplicateBankAccount($reference_id, $account_number, $bank_id) {
    //     $query = 'SELECT 1 FROM vms_bank_accounts WHERE reference_id = ? AND account_number = ? AND bank_id != ?';
    //     $this->logger->logQuery($query, [$reference_id, $account_number, $bank_id], 'classes');
    //     $duplicate = $this->conn->runSingle($query, [$reference_id, $account_number, $bank_id]);
    //     return !empty($duplicate);
    // }
}
?>
