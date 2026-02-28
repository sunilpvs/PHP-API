<?php
/* Table structure for ams_asset_family:
CREATE TABLE ams_asset_family (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_family VARCHAR(25) NOT NULL UNIQUE,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
); 

*/

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


class AssetFamily
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

    // get all asset families
    public function getAllAssetFamilies($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_family';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset families: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // asset families combo
    public function getAssetFamiliesCombo($module, $username)
    {
        try {
            $query = 'SELECT id, asset_family FROM ams_asset_family';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset families combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset family by id
    public function getAssetFamilyById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_family WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            return $this->conn->runQuery($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset family by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset family count
    public function getAssetFamilyCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS count FROM ams_asset_family';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runSingle($query);
            return $result ? $result['count'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset family count: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get paginated asset families
    public function getPaginatedAssetFamilies($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT * FROM ams_asset_family ORDER BY id DESC LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset families: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset family 
    public function insertAssetFamily($assetFamily, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_family (asset_family, created_by) VALUES (?, ?)';
            $params = [$assetFamily, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset family '$assetFamily' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset family: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset families from excel
    public function insertBatchAssetFamiliesFromExcel($assetFamilies, $createdBy)
    {
        if (empty($assetFamilies) || !is_array($assetFamilies)) {
            $this->logger->log('No asset families to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_family (asset_family, created_by) VALUES (?, ?)';
            $params = [];
            foreach ($assetFamilies as $assetFamily) {
                $params[] = [$assetFamily, $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset families from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    public function getExistingAssetFamiliesByNames(array $assetFamiliesLower, $module, $username)
    {
        if (empty($assetFamiliesLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetFamiliesLower), '?'));
            $query = "SELECT LOWER(asset_family) AS asset_family FROM ams_asset_family WHERE LOWER(asset_family) IN ($placeholders)";
            $this->logger->logQuery($query, $assetFamiliesLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetFamiliesLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_family']) ? strtolower($row['asset_family']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset families: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get family ID by family name (case-insensitive)
    public function getFamilyIdByName($familyName, $module, $username)
    {
        try {
            $query = 'SELECT id FROM ams_asset_family WHERE LOWER(TRIM(asset_family)) = LOWER(TRIM(?))';
            $params = [$familyName];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runSingle($query, $params);
            return $result ? (int)$result['id'] : null;
        } catch (Exception $e) {
            $this->logger->log('Error fetching family ID by name: ' . $e->getMessage(), 'classes', $module, $username);
            return null;
        }
    }

    // get multiple family IDs by family names (case-insensitive), returns associative array [normalized_name => id]
    public function getFamilyIdsByNames(array $familyNames, $module, $username)
    {
        if (empty($familyNames)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($familyNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_family)) AS normalized_name FROM ams_asset_family WHERE LOWER(TRIM(asset_family)) IN ($placeholders)";
            $this->logger->logQuery($query, $familyNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $familyNames);
            
            $result = [];
            foreach ($rows as $row) {
                if (isset($row['normalized_name']) && isset($row['id'])) {
                    $result[$row['normalized_name']] = (int)$row['id'];
                }
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->log('Error fetching family IDs by names: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset family 
    public function updateAssetFamily($id, $assetFamily, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_family SET asset_family = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetFamily, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset family ID $id updated to '$assetFamily' by user ID $lastUpdatedBy";
            $rows = $this->conn->update($query, $params, $logMessage);
            if($rows === 0) {
                return true; // No change but still valid
            }
            return $rows;
        } catch (Exception $e) {
            $this->logger->log('Error updating asset family: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete asset family
    public function deleteAssetFamily($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_family WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset family ID $id deleted by user $username";
            return $this->conn->delete($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset family: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate assset family for insert
    public function isDuplicateFamily($assetFamily, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_family WHERE asset_family = ?';
            $params = [$assetFamily];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset family: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset family for update
    public function isDuplicateFamilyForUpdate($id, $assetFamily, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_family WHERE asset_family = ? AND id != ?';
            $params = [$assetFamily, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset family for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}
