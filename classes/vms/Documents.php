<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class Documents {
    private $conn;
    private $logger;

    public function __construct() {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    public function getAllDocuments($module, $username) {
        $query = 'SELECT * FROM vms_documents';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getDocumentsByReferenceId($reference_id, $module, $username) {
        $query = 'SELECT * FROM vms_documents WHERE reference_id = ?';
        $this->logger->logQuery($query, [$reference_id], 'classes', $module, $username);
        return $this->conn->runQuery($query, [$reference_id]);
    }

    public function getDocumentById($doc_id, $module, $username) {
        $query = 'SELECT * FROM vms_documents WHERE doc_id = ?';
        $this->logger->logQuery($query, [$doc_id], 'classes', $module, $username);
        return $this->conn->runSingle($query, [$doc_id]);
    }

    public function insertDocument($reference_id, $doc_type, $file_path, $module, $username) {
        $query = 'INSERT INTO vms_documents (reference_id, doc_type, file_path) VALUES (?, ?, ?)';
        $params = [$reference_id, $doc_type, $file_path];
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Vendor document inserted';
        return $this->conn->insert($query, $params, $logMessage);
    }

    public function updateDocument($doc_id, $reference_id, $doc_type, $file_path, $module, $username) {
        $query = 'UPDATE vms_documents 
                SET file_path = ?, uploaded_at = NOW()
                WHERE reference_id = ? AND doc_id = ?';
        $params = [$file_path, $reference_id, $doc_id]; 
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $logMessage = 'Vendor document updated';
        return $this->conn->update($query, $params, $logMessage);
    }


    public function deleteDocument($doc_id, $module, $username) {
        $query = 'DELETE FROM vms_documents WHERE doc_id = ?';
        $this->logger->logQuery($query, [$doc_id], 'classes', $module, $username);
        $logMessage = 'Vendor document deleted';
        return $this->conn->update($query, [$doc_id], $logMessage);
    }

    public function getDocumentsCount($module, $username) {
        $query = 'SELECT COUNT(*) AS total FROM vms_documents';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getPaginatedDocuments($offset, $limit, $module, $username) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT 
                    d.doc_id,
                    d.reference_id,
                    v.vendor_name,
                    d.doc_type,
                    d.file_path,
                    d.uploaded_at
                  FROM vms_documents d
                  LEFT JOIN vms_vendor v ON d.reference_id = v.reference_id
                  ORDER BY d.uploaded_at DESC
                  LIMIT $limit OFFSET $offset";

        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }
}
?>
