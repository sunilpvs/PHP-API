<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

/* 
-- Table structure for table `ams_asset_models`
CREATE TABLE ams_asset_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_model VARCHAR(25) NOT NULL,
    config TEXT NOT NULL,
    asset_category_id INT NOT NULL,
    brand_id INT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_asset_model (asset_model, asset_category_id, brand_id),
    FOREIGN KEY (asset_category_id) REFERENCES ams_asset_category(id),
    FOREIGN KEY (brand_id) REFERENCES ams_asset_brands(id)
);
*/

class AssetModels
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

    // get all asset models
    public function getAllAssetModels($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_models';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset models: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // asset models combo
    public function getAssetModelsCombo($module, $username)
    {
        try {
            $query = 'SELECT id, asset_model FROM ams_asset_models';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset models combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset model by id
    public function getAssetModelById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_models WHERE id = ?';
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            return $this->conn->runSingle($query, [$id]);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset model by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset model count
    public function getAssetModelCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_models';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runQuery($query);
            return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset model count: ' . $e->getMessage(), 'classes', $module, $username);
            return 0;
        }
    }

    // get paginated asset models
    public function getPaginatedAssetModels($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT id, asset_model, config, asset_category_id, brand_id FROM ams_asset_models LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset models: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset model
    public function insertAssetModel($assetModel, $config, $assetCategoryId, $brandId, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_models (asset_model, config, asset_category_id, brand_id, created_by) VALUES (?, ?, ?, ?, ?)';
            $params = [$assetModel, $config, $assetCategoryId, $brandId, $createdBy];
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $logMessage = "Asset model '$assetModel' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset model: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset models from excel (each row has asset_model, config, asset_category_id, brand_id)
    public function insertBatchAssetModelsFromExcel($modelsData, $createdBy)
    {
        if (empty($modelsData) || !is_array($modelsData)) {
            $this->logger->log('No asset models to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_models (asset_model, config, asset_category_id, brand_id, created_by) VALUES (?, ?, ?, ?, ?)';
            $params = [];
            foreach ($modelsData as $row) {
                $params[] = [$row['asset_model'], $row['config'], $row['asset_category_id'], $row['brand_id'], $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset models from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    // check existing models by name (case-insensitive)
    public function getExistingAssetModelsByNames(array $modelsLower, $module, $username)
    {
        if (empty($modelsLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($modelsLower), '?'));
            $query = "SELECT LOWER(TRIM(asset_model)) AS asset_model FROM ams_asset_models WHERE LOWER(TRIM(asset_model)) IN ($placeholders)";
            $this->logger->logQuery($query, $modelsLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $modelsLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_model']) ? strtolower($row['asset_model']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset models: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get multiple asset model IDs by model names (case-insensitive)
    // returns ['map' => [normalized_name => id], 'duplicates' => [normalized_name => true]]
    public function getAssetModelIdsByNames(array $modelNames, $module, $username)
    {
        if (empty($modelNames)) {
            return ['map' => [], 'duplicates' => []];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($modelNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_model)) AS normalized_name FROM ams_asset_models WHERE LOWER(TRIM(asset_model)) IN ($placeholders)";
            $this->logger->logQuery($query, $modelNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $modelNames);

            $map = [];
            $duplicates = [];
            $counts = [];

            foreach ($rows as $row) {
                if (!isset($row['normalized_name']) || !isset($row['id'])) {
                    continue;
                }

                $name = $row['normalized_name'];
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                if ($counts[$name] === 1) {
                    $map[$name] = (int)$row['id'];
                } else {
                    $duplicates[$name] = true;
                }
            }

            foreach ($duplicates as $name => $value) {
                unset($map[$name]);
            }

            return ['map' => $map, 'duplicates' => $duplicates];
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset model IDs by names: ' . $e->getMessage(), 'classes', $module, $username);
            return ['map' => [], 'duplicates' => []];
        }
    }

    // check existing models by composite key (asset_model + category_id + brand_id), returns array of "model|category|brand" strings
    public function getExistingAssetModelsByCompositeKeys(array $modelData, $module, $username)
    {
        if (empty($modelData)) {
            return [];
        }

        try {
            $conditions = [];
            $params = [];
            foreach ($modelData as $data) {
                $conditions[] = '(LOWER(TRIM(asset_model)) = ? AND asset_category_id = ? AND brand_id = ?)';
                $params[] = strtolower(trim($data['asset_model']));
                $params[] = $data['asset_category_id'];
                $params[] = $data['brand_id'];
            }
            $conditionsStr = implode(' OR ', $conditions);
            $query = "SELECT LOWER(TRIM(asset_model)) AS asset_model, asset_category_id, brand_id FROM ams_asset_models WHERE $conditionsStr";
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $params);
            
            $result = [];
            foreach ($rows as $row) {
                $key = strtolower(trim($row['asset_model'])) . '|' . $row['asset_category_id'] . '|' . $row['brand_id'];
                $result[] = $key;
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset models by composite keys: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset model
    public function updateAssetModel($id, $assetModel, $config, $assetCategoryId, $brandId, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_models SET asset_model = ?, config = ?, asset_category_id = ?, brand_id = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetModel, $config, $assetCategoryId, $brandId, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset model ID $id updated to '$assetModel' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if($rows === 0) {
                return true; // No change but still valid
            }
            return $rows;
        } catch (Exception $e) {
            $this->logger->log('Error updating asset model: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete asset model
    public function deleteAssetModel($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_models WHERE id = ?';
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            $logMessage = "Asset model ID $id deleted by user $username";
            return $this->conn->delete($query, [$id], $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset model: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate asset model for insert (composite key: asset_model + category_id + brand_id)
    public function isDuplicateAssetModel($assetModel, $assetCategoryId, $brandId, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_models WHERE LOWER(TRIM(asset_model)) = LOWER(TRIM(?)) AND asset_category_id = ? AND brand_id = ?';
            $params = [trim($assetModel), $assetCategoryId, $brandId];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset model: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset model for update (composite key: asset_model + category_id + brand_id)
    public function isDuplicateAssetModelForUpdate($id, $assetModel, $assetCategoryId, $brandId, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_models WHERE LOWER(TRIM(asset_model)) = LOWER(TRIM(?)) AND asset_category_id = ? AND brand_id = ? AND id != ?';
            $params = [trim($assetModel), $assetCategoryId, $brandId, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset model for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}