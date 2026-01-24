<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Comments {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllComments($module, $username) {
        $query = 'SELECT * FROM vms_comments';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getCommentsByVendor($reference_id, $module, $username) {
        $query = "SELECT * FROM vms_comments WHERE reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$reference_id]);
    }

   
    public function getCommentsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM vms_comments';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getLatestCommentsByReferenceId($reference_id, $module, $username) {
        $query = "SELECT * FROM vms_comments WHERE email_sent = 0 AND reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$reference_id]);
    }

    public function getPreviousCommentsByReferenceId($reference_id, $module, $username){
        $query = "SELECT c.step_name AS step, c.comment_text AS comment, DATE_FORMAT(c.created_at, '%d-%m-%Y') AS commented_on, concat(cont.f_name,' ',cont.l_name) AS commenter
                        FROM vms_comments c 
                        JOIN tbl_contact cont ON c.created_by = cont.email
                        WHERE c.email_sent = 1 AND c.reference_id = ?";
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        $comments = $this->conn->runQuery($query, [$reference_id]);
        $formattedComments = [];
        foreach($comments as $comment){
            $split_comment = explode("\n", $comment['comment']);
            
            foreach($split_comment as $line){
                $formattedComments[] = [
                    'step' => $comment['step'],
                    'comment' => trim($line),
                    'commented_on' => $comment['commented_on'],
                    'commenter' => $comment['commenter']
                ];
            }
        }
        return $formattedComments;
    }

    public function updateEmailSentStatus($reference_id, $email_sent, $module, $username) {
        $query = "UPDATE vms_comments SET email_sent = ? WHERE reference_id = ?";
        $params = [$email_sent, $reference_id];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Email sent status updated for comments';

        return $this->conn->update($query, $params, $logMessage);
    }
    
    

    public function insertComments($reference_id, $step_name, $comment_text, $module, $username) {
        $query = "INSERT INTO vms_comments (
            reference_id, step_name, comment_text, created_by
        ) VALUES (?, ?, ?, ?)";

        $params = [$reference_id, $step_name, $comment_text, $username];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Comments inserted ';

        return $this->conn->insert($query, $params, $logMessage);
    }

    public function updateComments($reference_id, $step_name, $comment_text, $comment_id, $module, $username) {
        $query = 'UPDATE vms_comments SET 
            reference_id = ?, 
            step_name = ?, 
            comment_text = ?, 
            WHERE comment_id = ?';

        $params = [$reference_id, $step_name, $comment_text, $comment_id];

        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Comments updated';

        return $this->conn->update($query, $params, $logMessage);
    }


    public function deleteComments($comment_id, $module, $username) {
        $query = 'DELETE FROM vms_comments WHERE comment_id = ?';
        $this->logger->logQuery($query, [$comment_id], 'classes', $module, $username);
        $logMessage = 'Comments deleted';
        return $this->conn->update($query, [$comment_id], $logMessage);
    }

    public function checkDuplicateBankAccount($reference_id, $account_number) {
        $query = 'SELECT 1 FROM vms_vendor_bank_accounts WHERE reference_id = ? AND account_number = ?';
        $this->logger->logQuery($query, [$reference_id, $account_number], 'classes');
        $duplicate = $this->conn->runSingle($query, [$reference_id, $account_number]);
        return !empty($duplicate);
    }

    public function checkEditDuplicateBankAccount($reference_id, $account_number, $bank_id) {
        $query = 'SELECT 1 FROM vms_vendor_bank_accounts WHERE reference_id = ? AND account_number = ? AND bank_id != ?';
        $this->logger->logQuery($query, [$reference_id, $account_number, $bank_id], 'classes');
        $duplicate = $this->conn->runSingle($query, [$reference_id, $account_number, $bank_id]);
        return !empty($duplicate);
    }
}
?>
