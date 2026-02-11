<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


/* 
Table structure for ams_asset_brands:
    CREATE TABLE ams_asset_brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brand VARCHAR(25) NOT NULL UNIQUE,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
*/

class AssetBrands
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

    // get All Asset Brands
    public function getAllAssetBrands($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_brands';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset brands: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // Asset Brands combo
    public function getAssetBrandsCombo($module, $username)
    {
        try {
            $query = 'SELECT id, brand FROM ams_asset_brands';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset brands combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get Asset Brands by id
    public function getAssetBrandById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_brands WHERE id = ?';
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            return $this->conn->runSingle($query, [$id]);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset brand by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get Asset Brands count
    public function getAssetBrandCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_brands';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runQuery($query);
            return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset brand count: ' . $e->getMessage(), 'classes', $module, $username);
            return 0;
        }
    }

    // get Paginated Asset Brands
    public function getPaginatedAssetBrands($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT id, brand FROM ams_asset_brands LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset brands: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert Asset Brands
    public function insertAssetBrand($brand, $created_by, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_brands (brand, created_by) VALUES (?, ?)';
            $this->logger->logQuery($query, [$brand, $created_by], 'classes', $module, $username);
            return $this->conn->insert($query, [$brand, $created_by]);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset brand: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }


    // insert batch asset brands from excel
    public function insertBatchAssetBrandsFromExcel($brands, $created_by)
    {
        if (empty($brands) || !is_array($brands)) {
            $this->logger->log('No brands to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }
        
        try {
            $query = 'INSERT INTO ams_asset_brands (brand, created_by) VALUES (?, ?)';
            $params = [];
            foreach ($brands as $brand) {
                $params[] = [$brand, $created_by];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset brands from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    public function getExistingBrandsByNames(array $brandsLower, $module, $username)
    {
        if (empty($brandsLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($brandsLower), '?'));
            $query = "SELECT LOWER(brand) AS brand FROM ams_asset_brands WHERE LOWER(brand) IN ($placeholders)";
            $this->logger->logQuery($query, $brandsLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $brandsLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['brand']) ? strtolower($row['brand']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing brands: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update Asset Brands
    public function updateAssetBrand($id, $brand, $last_updated_by, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_brands SET brand = ?, last_updated_by = ? WHERE id = ?';
            $this->logger->logQuery($query, [$brand, $last_updated_by, $id], 'classes', $module, $username);
            return $this->conn->update($query, [$brand, $last_updated_by, $id]);
        } catch (Exception $e) {
            $this->logger->log('Error updating asset brand: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete Asset Brands
    public function deleteAssetBrand($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_brands WHERE id = ?';
            $this->logger->logQuery($query, [$id], 'classes', $module, $username);
            return $this->conn->delete($query, [$id]);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset brand: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate brand for insert
    public function isDuplicateBrand($brand, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_brands WHERE brand = ?';
            $this->logger->logQuery($query, [$brand], 'classes', $module, $username);
            $result = $this->conn->runQuery($query, [$brand]);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate brand: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate brand for update
    public function isDuplicateBrandForUpdate($id, $brand, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_brands WHERE brand = ? AND id != ?';
            $this->logger->logQuery($query, [$brand, $id], 'classes', $module, $username);
            $result = $this->conn->runQuery($query, [$brand, $id]);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate brand for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}
