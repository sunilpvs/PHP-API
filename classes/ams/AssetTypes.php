<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

/* 
CREATE TABLE ams_asset_type (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_type VARCHAR(25) NOT NULL,
    asset_category_id INT NOT NULL,
    assignment_type_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_category (asset_type, asset_category_id, assignment_type_id),
    FOREIGN KEY (asset_category_id) REFERENCES ams_asset_category(id),
    FOREIGN KEY (assignment_type_id) REFERENCES ams_assignment_type(id)
);
*/

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
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            return $this->conn->runSingle($query, [$id]);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset type by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset type count
    public function getAssetTypeCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_type';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runQuery($query);
            return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset type count: ' . $e->getMessage(), 'classes', $module, $username);
            return 0;
        }
    }

    // get paginated asset types
    public function getPaginatedAssetTypes($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT id, asset_type, asset_category_id, assignment_type_id FROM ams_asset_type LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset types: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset type
    public function insertAssetType($assetType, $assetCategoryId, $assignmentTypeId, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_type (asset_type, asset_category_id, assignment_type_id, created_by) VALUES (?, ?, ?, ?)';
            $params = [$assetType, $assetCategoryId, $assignmentTypeId, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset type '$assetType' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset types from excel (each row has asset_type, asset_category_id, assignment_type_id)
    public function insertBatchAssetTypesFromExcel($typesData, $createdBy)
    {
        if (empty($typesData) || !is_array($typesData)) {
            $this->logger->log('No asset types to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_type (asset_type, asset_category_id, assignment_type_id, created_by) VALUES (?, ?, ?, ?)';
            $params = [];
            foreach ($typesData as $row) {
                $params[] = [$row['asset_type'], $row['asset_category_id'], $row['assignment_type_id'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset types from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    // check existing types by name (case-insensitive)
    public function getExistingAssetTypesByNames(array $typesLower, $module, $username)
    {
        if (empty($typesLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($typesLower), '?'));
            $query = "SELECT LOWER(TRIM(asset_type)) AS asset_type FROM ams_asset_type WHERE LOWER(TRIM(asset_type)) IN ($placeholders)";
            $this->logger->logQuery($query, $typesLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $typesLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_type']) ? strtolower($row['asset_type']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset types: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // check existing type + asset_category_id + assignment_type_id combinations (case-insensitive category)
    // $combinations format: [['asset_category_normalized' => 'laptop', 'asset_category_id' => 1, 'assignment_type_id' => 2], ...]
    // returns array of normalized keys in the format "category|asset_category_id|assignment_type_id"
    public function getExistingAssetTypeCombinations(array $combinations, $module, $username)
    {
        if (empty($combinations)) {
            return [];
        }

        try {
            $whereClauses = [];
            $params = [];

            foreach ($combinations as $combination) {
                if (
                    !isset($combination['asset_type_normalized']) ||
                    !isset($combination['asset_category_id']) ||
                    !isset($combination['assignment_type_id'])
                ) {
                    continue;
                }

                $whereClauses[] = '(LOWER(TRIM(asset_type)) = ? AND asset_category_id = ? AND assignment_type_id = ?)';
                $params[] = strtolower(trim($combination['asset_type_normalized']));
                $params[] = (int)$combination['asset_category_id'];
                $params[] = (int)$combination['assignment_type_id'];
            }

            if (empty($whereClauses)) {
                return [];
            }

            $query = 'SELECT LOWER(TRIM(asset_type)) AS asset_type_normalized, asset_category_id, assignment_type_id FROM ams_asset_type WHERE ' . implode(' OR ', $whereClauses);
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $params);

            $existingKeys = [];
            foreach ($rows as $row) {
                if (isset($row['asset_type_normalized'], $row['asset_category_id'], $row['assignment_type_id'])) {
                    $existingKeys[] = $row['asset_type_normalized'] . '|' . (int)$row['asset_category_id'] . '|' . (int)$row['assignment_type_id'];
                }
            }

            return array_values(array_unique($existingKeys));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset type combinations: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get multiple asset type IDs by category names (case-insensitive), returns associative array [normalized_name => id]
    public function getAssetTypeIdsByNames(array $typeNames, $module, $username)
    {
        if (empty($typeNames)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($typeNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_type)) AS normalized_name FROM ams_asset_type WHERE LOWER(TRIM(asset_type)) IN ($placeholders)";
            $this->logger->logQuery($query, $typeNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $typeNames);

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
    public function updateAssetType($id, $assetType, $assetCategoryId, $assignmentTypeId, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_type SET asset_type = ?, asset_category_id = ?, assignment_type_id = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetType, $assetCategoryId, $assignmentTypeId, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset type ID $id updated to '$assetType' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if($rows === 0) {
                return true; // No change but still valid
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
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            $logMessage = "Asset type ID $id deleted by user $username";
            return $this->conn->delete($query, [$id], $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate asset type for insert
    public function isDuplicateAssetType($assetType, $assetCategoryId, $assignmentTypeId, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_type WHERE LOWER(TRIM(asset_type)) = LOWER(TRIM(?)) AND asset_category_id = ? AND assignment_type_id = ?';
            $params = [$assetType, $assetCategoryId, $assignmentTypeId];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset type: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset type for update
    public function isDuplicateAssetTypeForUpdate($id, $assetType, $assetCategoryId, $assignmentTypeId, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_type WHERE LOWER(TRIM(asset_type)) = LOWER(TRIM(?)) AND asset_category_id = ? AND assignment_type_id = ? AND id != ?';
            $params = [$assetType, $assetCategoryId, $assignmentTypeId, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset type for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}

