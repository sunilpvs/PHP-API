<?php
/* Table Structure for ams_asset_type:
    CREATE TABLE ams_asset_type (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_type VARCHAR(25) NOT NULL UNIQUE,
    asset_group_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_group_id) REFERENCES ams_asset_group(id)
);
*/


require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


class AssetTypes
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

    // get all asset types
    public function getAllAssetTypes($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset types: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // asset types combo
    public function getAssetTypesCombo($module, $username)
    {
        try {
            $query = 'SELECT id, asset_type FROM ams_asset_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset types combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset type by id
    public function getAssetTypeById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_type WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            return $this->conn->runQuery($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset type by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset type count
    public function getAssetTypeCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS count FROM ams_asset_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runSingle($query);
            return $result ? $result['count'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset type count: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get paginated asset types
    public function getPaginatedAssetTypes($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT * FROM ams_asset_type ORDER BY id DESC LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset types: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset type 
    public function insertAssetType($assetType, $groupId, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_type (asset_type, asset_group_id, created_by) VALUES (?, ?, ?)';
            $params = [$assetType, $groupId, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset type '$assetType' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);

        } catch (Exception $e) {
            $this->logger->log('Error inserting asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset types from excel (each row has assetType and groupId)
    public function insertBatchAssetTypesFromExcel($assetTypesData, $createdBy)
    {
        if (empty($assetTypesData) || !is_array($assetTypesData)) {
            $this->logger->log('No asset types to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_type (asset_type, asset_group_id, created_by) VALUES (?, ?, ?)';
            $params = [];
            foreach ($assetTypesData as $row) {
                $params[] = [$row['asset_type'], $row['group_id'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset types from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    public function getExistingAssetTypesByNames(array $assetTypesLower, $module, $username)
    {
        if (empty($assetTypesLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetTypesLower), '?'));
            $query = "SELECT LOWER(asset_type) AS asset_type FROM ams_asset_type WHERE LOWER(asset_type) IN ($placeholders)";
            $this->logger->logQuery($query, $assetTypesLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetTypesLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_type']) ? strtolower($row['asset_type']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset types: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get multiple asset type IDs by asset type names (case-insensitive), returns associative array [normalized_name => id]
    public function getAssetTypeIdsByNames(array $assetTypeNames, $module, $username)
    {
        if (empty($assetTypeNames)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetTypeNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_type)) AS normalized_name FROM ams_asset_type WHERE LOWER(TRIM(asset_type)) IN ($placeholders)";
            $this->logger->logQuery($query, $assetTypeNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetTypeNames);

            $result = [];
            foreach ($rows as $row) {
                if (isset($row['normalized_name']) && isset($row['id'])) {
                    $result[$row['normalized_name']] = (int)$row['id'];
                }
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset type IDs by names: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset type
    public function updateAssetType($id, $assetType, $groupId, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_type SET asset_type = ?, asset_group_id = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetType, $groupId, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset type ID $id updated to '$assetType' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if ($rows === 0) {
                return true; // Consider no rows affected as a successful update if no error occurred
            }
            return $rows;
        } catch (Exception $e) {
            $this->logger->log('Error updating asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete asset type
    public function deleteAssetType($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_type WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset type ID $id deleted by user $username";
            return $this->conn->delete($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate asset type for insert
    public function isDuplicateAssetType($assetType, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_type WHERE asset_type = ?';
            $params = [$assetType];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset type for update
    public function isDuplicateAssetTypeForUpdate($id, $assetType, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_type WHERE asset_type = ? AND id != ?';
            $params = [$assetType, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset type for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}
