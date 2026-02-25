<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

/* 
CREATE TABLE ams_asset_category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_category VARCHAR(25) NOT NULL UNIQUE,
    asset_type_id INT NOT NULL,
    assignment_type_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_type_id) REFERENCES ams_asset_type(id),
    FOREIGN KEY (assignment_type_id) REFERENCES ams_assignment_type(id)
);
*/

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
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            return $this->conn->runSingle($query, [$id]);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset category by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset category count
    public function getAssetCategoryCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_category';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runQuery($query);
            return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset category count: ' . $e->getMessage(), 'classes', $module, $username);
            return 0;
        }
    }

    // get paginated asset categories
    public function getPaginatedAssetCategories($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT id, asset_category, asset_type_id, assignment_type_id FROM ams_asset_category LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset categories: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset category
    public function insertAssetCategory($assetCategory, $assetTypeId, $assignmentTypeId, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_category (asset_category, asset_type_id, assignment_type_id, created_by) VALUES (?, ?, ?, ?)';
            $params = [$assetCategory, $assetTypeId, $assignmentTypeId, $createdBy];
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $logMessage = "Asset category '$assetCategory' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset categories from excel (each row has asset_category, asset_type_id, assignment_type_id)
    public function insertBatchAssetCategoriesFromExcel($categoriesData, $createdBy)
    {
        if (empty($categoriesData) || !is_array($categoriesData)) {
            $this->logger->log('No asset categories to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_category (asset_category, asset_type_id, assignment_type_id, created_by) VALUES (?, ?, ?, ?)';
            $params = [];
            foreach ($categoriesData as $row) {
                $params[] = [$row['asset_category'], $row['asset_type_id'], $row['assignment_type_id'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset categories from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    // check existing categories by name (case-insensitive)
    public function getExistingAssetCategoriesByNames(array $categoriesLower, $module, $username)
    {
        if (empty($categoriesLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($categoriesLower), '?'));
            $query = "SELECT LOWER(TRIM(asset_category)) AS asset_category FROM ams_asset_category WHERE LOWER(TRIM(asset_category)) IN ($placeholders)";
            $this->logger->logQuery($query, $categoriesLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $categoriesLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_category']) ? strtolower($row['asset_category']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset categories: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get multiple asset category IDs by category names (case-insensitive), returns associative array [normalized_name => id]
    public function getAssetCategoryIdsByNames(array $categoryNames, $module, $username)
    {
        if (empty($categoryNames)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($categoryNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_category)) AS normalized_name FROM ams_asset_category WHERE LOWER(TRIM(asset_category)) IN ($placeholders)";
            $this->logger->logQuery($query, $categoryNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $categoryNames);

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
    public function updateAssetCategory($id, $assetCategory, $assetTypeId, $assignmentTypeId, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_category SET asset_category = ?, asset_type_id = ?, assignment_type_id = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetCategory, $assetTypeId, $assignmentTypeId, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset category ID $id updated to '$assetCategory' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if($rows === 0) {
                return true; // No change but still valid
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
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            $logMessage = "Asset category ID $id deleted by user $username";
            return $this->conn->delete($query, [$id], $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate asset category for insert
    public function isDuplicateAssetCategory($assetCategory, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_category WHERE asset_category = ?';
            $params = [$assetCategory];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset category: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset category for update
    public function isDuplicateAssetCategoryForUpdate($id, $assetCategory, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_category WHERE asset_category = ? AND id != ?';
            $params = [$assetCategory, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset category for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}

