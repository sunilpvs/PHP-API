<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

/* 
-- Table structure for table `ams_asset_info`
CREATE TABLE ams_asset_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_family_id INT NOT NULL,
    asset_category_id INT NOT NULL,
    asset_type_id INT NOT NULL,
    asset_model_id INT NOT NULL,
    asset_serial_number VARCHAR(25) NOT NULL UNIQUE COLLATE utf8mb4_unicode_ci,
    asset_purchase_date DATE NOT NULL,
    asset_price DECIMAL(10, 2) NOT NULL,
    asset_warranty_expiry DATE NOT NULL,
    asset_extended_warranty DATE NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_info (asset_family_id, asset_category_id, asset_type_id, asset_model_id, asset_serial_number),
    FOREIGN KEY (asset_family_id) REFERENCES ams_asset_family(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (asset_category_id) REFERENCES ams_asset_category(id)  ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (asset_type_id) REFERENCES ams_asset_type(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (asset_model_id) REFERENCES ams_asset_models(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK (asset_price >= 0),
    CHECK (asset_warranty_expiry >= asset_purchase_date)
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
            $query = "SELECT id, asset_family_id, asset_category_id, asset_type_id, asset_model_id, asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_extended_warranty FROM ams_asset_info LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset info
    public function insertAssetInfo($assetFamilyId, $assetCategoryId, $assetTypeId, $assetModelId, $serialNumber, $purchaseDate, $price, $warrantyExpiry, $extendedWarranty, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_info (asset_family_id, asset_category_id, asset_type_id, asset_model_id, asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_extended_warranty, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params = [$assetFamilyId, $assetCategoryId, $assetTypeId, $assetModelId, $serialNumber, $purchaseDate, $price, $warrantyExpiry, $extendedWarranty, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
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
            $query = 'INSERT INTO ams_asset_info (asset_family_id, asset_category_id, asset_type_id, asset_model_id, asset_serial_number, asset_purchase_date, asset_price, asset_warranty_expiry, asset_extended_warranty, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params = [];
            foreach ($infoData as $row) {
                $params[] = [$row['asset_family_id'], $row['asset_category_id'], $row['asset_type_id'], $row['asset_model_id'], $row['asset_serial_number'], $row['asset_purchase_date'], $row['asset_price'], $row['asset_warranty_expiry'], $row['asset_extended_warranty'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset info from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    // check existing asset info (asset_family_id + asset_category_id + asset_type_id + asset_model_id + asset_serial_number) for batch insert
    public function getExistingAssetInfoByCompositeKeys(array $modelData, $module, $username){
         if (empty($modelData)) {
            return [];
        }

        try {
            $conditions = [];
            $params = [];
            foreach ($modelData as $data) {
                $conditions[] = '(asset_family_id = ? AND asset_category_id = ? AND asset_type_id = ? AND asset_model_id = ? AND LOWER(TRIM(asset_serial_number)) = ?)';
                $params[] = $data['asset_family_id'];
                $params[] = $data['asset_category_id'];
                $params[] = $data['asset_type_id'];
                $params[] = $data['asset_model_id'];
                $params[] = strtolower(trim($data['asset_serial_number']));
            }
            $query = "SELECT asset_family_id, asset_category_id, asset_type_id, asset_model_id, LOWER(TRIM(asset_serial_number)) AS asset_serial_number FROM ams_asset_info WHERE " . implode(' OR ', $conditions);
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $params);

            $result = [];
            foreach ($rows as $row) {
                $key = strtolower(trim($row['asset_serial_number'])) . '|' . $row['asset_family_id'] . '|' . $row['asset_category_id'] . '|' . $row['asset_type_id'] . '|' . $row['asset_model_id'];
                $result[] = $key;
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset info by composite keys: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset info
    public function updateAssetInfo($id, $asset_family_id,  $asset_category_id, $asset_type_id, $asset_model_id, $serialNumber, $purchaseDate, $price, $warrantyExpiry, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_info SET asset_family_id = ?, asset_category_id = ?, asset_type_id = ?, asset_model_id = ?, asset_serial_number = ?, asset_purchase_date = ?, asset_price = ?, asset_warranty_expiry = ?, last_updated_by = ? WHERE id = ?';
            $params = [$asset_family_id, $asset_category_id, $asset_type_id, $asset_model_id, $serialNumber, $purchaseDate, $price, $warrantyExpiry, $lastUpdatedBy, $id];
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


    // get family/category/type IDs by asset model IDs
    public function getAssetHierarchyByModelIds(array $assetModelIds, $module, $username)
    {
        if (empty($assetModelIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetModelIds), '?'));
            $query = "SELECT m.id AS asset_model_id, m.asset_category_id, m.asset_type_id, c.asset_family_id FROM ams_asset_models m INNER JOIN ams_asset_category c ON c.id = m.asset_category_id WHERE m.id IN ($placeholders)";
            $this->logger->logQuery($query, $assetModelIds, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetModelIds);

            $map = [];
            foreach ($rows as $row) {
                if (!isset($row['asset_model_id'], $row['asset_family_id'], $row['asset_category_id'], $row['asset_type_id'])) {
                    continue;
                }

                $modelId = (int)$row['asset_model_id'];
                $map[$modelId] = [
                    'asset_family_id' => (int)$row['asset_family_id'],
                    'asset_category_id' => (int)$row['asset_category_id'],
                    'asset_type_id' => (int)$row['asset_type_id'],
                    'asset_model_id' => $modelId,
                ];
            }

            return $map;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset hierarchy by model IDs: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // helper methods

    // check duplicate asset info for insert
    public function isDuplicateAssetInfo($asset_family_id, $asset_category_id, $asset_type_id, $asset_model_id, $serialNumber, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_info WHERE asset_family_id = ? AND asset_category_id = ? AND asset_type_id = ? AND asset_model_id = ? AND asset_serial_number = ?';
            $params = [$asset_family_id, $asset_category_id, $asset_type_id, $asset_model_id, $serialNumber];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset info: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset info for update
    public function isDuplicateAssetInfoForUpdate($id, $asset_family_id, $asset_category_id, $asset_type_id, $asset_model_id, $serialNumber, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_info WHERE asset_family_id = ? AND asset_category_id = ? AND asset_type_id = ? AND asset_model_id = ? AND asset_serial_number = ? AND id != ?';
            $params = [$asset_family_id, $asset_category_id, $asset_type_id, $asset_model_id, $serialNumber, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset info for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}

