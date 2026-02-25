<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

/* 
-- Table structure for table `ams_asset_info`
CREATE TABLE ams_asset_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_serial_number VARCHAR(25) NOT NULL UNIQUE,
    asset_purchase_date DATE NOT NULL,
    asset_price DECIMAL(10, 2) NOT NULL,
    asset_warranty_expiry DATE NOT NULL,
    asset_model_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_model_id) REFERENCES ams_asset_models(id)
);
*/

class AssetInfo
{
    private $conn;
    private $logger;

    public function __construct()
    {
        $this->conn = new DBController();
        $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
        $debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    // get all asset info
    public function getAllAssetInfo($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_info';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // asset info combo
    public function getAssetInfoCombo($module, $username)
    {
        try {
            $query = 'SELECT id, asset_serial_number FROM ams_asset_info';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset info combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset info by id
    public function getAssetInfoById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_info WHERE id = ?';
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            return $this->conn->runSingle($query, [$id]);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset info by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset info count
    public function getAssetInfoCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_info';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runQuery($query);
            return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset info count: ' . $e->getMessage(), 'classes', $module, $username);
            return 0;
        }
    }

    // get paginated asset info
    public function getPaginatedAssetInfo($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT id, asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_model_id FROM ams_asset_info LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset info
    public function insertAssetInfo($serialNumber, $purchaseDate, $price, $warrantyExpiry, $assetModelId, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_info (asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_model_id, created_by) VALUES (?, ?, ?, ?, ?, ?)';
            $params = [$serialNumber, $purchaseDate, $price, $warrantyExpiry, $assetModelId, $createdBy];
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $logMessage = "Asset info '$serialNumber' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset info from excel (each row has asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_model_id)
    public function insertBatchAssetInfoFromExcel($infoData, $createdBy)
    {
        if (empty($infoData) || !is_array($infoData)) {
            $this->logger->log('No asset info to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_info (asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_model_id, created_by) VALUES (?, ?, ?, ?, ?, ?)';
            $params = [];
            foreach ($infoData as $row) {
                $params[] = [$row['asset_serial_number'], $row['asset_purchase_date'], $row['asset_price'], $row['asset_warranty_expiry'], $row['asset_model_id'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset info from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    // check existing serial numbers (case-insensitive)
    public function getExistingAssetInfoBySerials(array $serialsLower, $module, $username)
    {
        if (empty($serialsLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($serialsLower), '?'));
            $query = "SELECT LOWER(TRIM(asset_serial_number)) AS asset_serial_number FROM ams_asset_info WHERE LOWER(TRIM(asset_serial_number)) IN ($placeholders)";
            $this->logger->logQuery($query, $serialsLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $serialsLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_serial_number']) ? strtolower($row['asset_serial_number']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset serial numbers: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset info
    public function updateAssetInfo($id, $serialNumber, $purchaseDate, $price, $warrantyExpiry, $assetModelId, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_info SET asset_serial_number = ?, asset_purchase_date = ?, asset_price = ?, asset_warranty_expiry = ?, asset_model_id = ?, last_updated_by = ? WHERE id = ?';
            $params = [$serialNumber, $purchaseDate, $price, $warrantyExpiry, $assetModelId, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset info ID $id updated to '$serialNumber' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if($rows == 0) {
                return true; // No change but still successful
            }

            return $rows;
        } catch (Exception $e) {
            $this->logger->log('Error updating asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete asset info
    public function deleteAssetInfo($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_info WHERE id = ?';
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            $logMessage = "Asset info ID $id deleted by user $username";
            return $this->conn->delete($query, [$id], $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate asset info for insert
    public function isDuplicateAssetInfo($serialNumber, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_info WHERE asset_serial_number = ?';
            $params = [$serialNumber];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset info for update
    public function isDuplicateAssetInfoForUpdate($id, $serialNumber, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_info WHERE asset_serial_number = ? AND id != ?';
            $params = [$serialNumber, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset info for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}

