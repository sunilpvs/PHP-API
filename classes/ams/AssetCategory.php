<?php
/* Table Structure for ams_asset_category:
CREATE TABLE ams_asset_category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_category VARCHAR(25) NOT NULL,
    asset_family_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unq_asset_category (asset_category, asset_family_id),
    FOREIGN KEY (asset_family_id) REFERENCES ams_asset_family(id)
);
*/


require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


class AssetCategory
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

    // get all asset categories
    public function getAllAssetCategories($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_category';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset categories: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // asset categories combo
    public function getAssetCategoriesCombo($module, $username)
    {
        try {
            $query = 'SELECT id, asset_category FROM ams_asset_category';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset categories combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset category by id
    public function getAssetCategoryById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_category WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            return $this->conn->runQuery($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset category by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset category count
    public function getAssetCategoryCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS count FROM ams_asset_category';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runSingle($query);
            return $result ? $result['count'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset category count: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get paginated asset categories
    public function getPaginatedAssetCategories($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT * FROM ams_asset_category ORDER BY id DESC LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset categories: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset category 
    public function insertAssetCategory($assetCategory, $groupId, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_category (asset_category, asset_family_id, created_by) VALUES (?, ?, ?)';
            $params = [$assetCategory, $groupId, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset category '$assetCategory' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);

        } catch (Exception $e) {
            $this->logger->log('Error inserting asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset categories from excel (each row has assetCategory and groupId)
    public function insertBatchAssetCategoriesFromExcel($assetCategoriesData, $createdBy)
    {
        if (empty($assetCategoriesData) || !is_array($assetCategoriesData)) {
            $this->logger->log('No asset categories to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_category (asset_category, asset_family_id, created_by) VALUES (?, ?, ?)';
            $params = [];
            foreach ($assetCategoriesData as $row) {
                $params[] = [$row['asset_category'], $row['group_id'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset categories from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    public function getExistingAssetCategoriesByNames(array $assetCategoriesLower, $module, $username)
    {
        if (empty($assetCategoriesLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetCategoriesLower), '?'));
            $query = "SELECT LOWER(asset_category) AS asset_category FROM ams_asset_category WHERE LOWER(asset_category) IN ($placeholders)";
            $this->logger->logQuery($query, $assetCategoriesLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetCategoriesLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_category']) ? strtolower($row['asset_category']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset categories: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // check existing asset_category + asset_family_id combinations (case-insensitive asset_category)
    // $combinations format: [['asset_category_normalized' => 'laptop', 'group_id' => 1], ...]
    // returns array of normalized keys in the format "asset_category|group_id"
    public function getExistingAssetCategoryCombinations(array $combinations, $module, $username)
    {
        if (empty($combinations)) {
            return [];
        }

        try {
            $whereClauses = [];
            $params = [];

            foreach ($combinations as $combination) {
                if (!isset($combination['asset_category_normalized']) || !isset($combination['group_id'])) {
                    continue;
                }

                $whereClauses[] = '(LOWER(TRIM(asset_category)) = ? AND asset_family_id = ?)';
                $params[] = strtolower(trim($combination['asset_category_normalized']));
                $params[] = (int)$combination['group_id'];
            }

            if (empty($whereClauses)) {
                return [];
            }

            $query = 'SELECT LOWER(TRIM(asset_category)) AS asset_category_normalized, asset_family_id FROM ams_asset_category WHERE ' . implode(' OR ', $whereClauses);
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $params);

            $existingKeys = [];
            foreach ($rows as $row) {
                if (isset($row['asset_category_normalized'], $row['asset_family_id'])) {
                    $existingKeys[] = $row['asset_category_normalized'] . '|' . (int)$row['asset_family_id'];
                }
            }

            return array_values(array_unique($existingKeys));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset category combinations: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get multiple asset category IDs by asset category names (case-insensitive), returns associative array [normalized_name => id]
    public function getAssetCategoryIdsByNames(array $assetCategoryNames, $module, $username)
    {
        if (empty($assetCategoryNames)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetCategoryNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_category)) AS normalized_name FROM ams_asset_category WHERE LOWER(TRIM(asset_category)) IN ($placeholders)";
            $this->logger->logQuery($query, $assetCategoryNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetCategoryNames);

            $result = [];
            foreach ($rows as $row) {
                if (isset($row['normalized_name']) && isset($row['id'])) {
                    $result[$row['normalized_name']] = (int)$row['id'];
                }
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset category IDs by names: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset category
    public function updateAssetCategory($id, $assetCategory, $groupId, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_category SET asset_category = ?, asset_family_id = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetCategory, $groupId, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset category ID $id updated to '$assetCategory' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if ($rows === 0) {
                return true; // Consider no rows affected as a successful update if no error occurred
            }
            return $rows;
        } catch (Exception $e) {
            $this->logger->log('Error updating asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete asset category
    public function deleteAssetCategory($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_category WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset category ID $id deleted by user $username";
            return $this->conn->delete($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate asset category for insert
    // check if asset category already exists for the same group 
    // same asset category name can exist in different groups, but not in the same group
    public function isDuplicateAssetCategory($assetCategory, $groupId, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_category WHERE LOWER(TRIM(asset_category)) = LOWER(TRIM(?)) AND asset_family_id = ?';
            $params = [$assetCategory, $groupId];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset category for update
    // when updating, we need to exclude the current record from the duplicate check
    // check if asset category already exists for the same group excluding the current record
    public function isDuplicateAssetCategoryForUpdate($id, $assetCategory, $groupId, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_category WHERE LOWER(TRIM(asset_category)) = LOWER(TRIM(?)) AND asset_family_id = ? AND id != ?';
            $params = [$assetCategory, $groupId, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset category for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}
