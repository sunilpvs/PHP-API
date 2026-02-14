<?php
/* Table structure for ams_asset_group:
    CREATE TABLE ams_asset_group (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_group VARCHAR(25) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated_by INT DEFAULT NULL,
    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);  

*/

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


class AssetGroups
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

    // get all asset groups
    public function getAllAssetGroups($module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_group';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching all asset groups: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // asset groups combo
    public function getAssetGroupsCombo($module, $username)
    {
        try {
            $query = 'SELECT id, asset_group FROM ams_asset_group';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset groups combo: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset group by id
    public function getAssetGroupById($id, $module, $username)
    {
        try {
            $query = 'SELECT * FROM ams_asset_group WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            return $this->conn->runQuery($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset group by id: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get asset group count
    public function getAssetGroupCount($module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS count FROM ams_asset_group';
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            $result = $this->conn->runSingle($query);
            return $result ? $result['count'] : 0;
        } catch (Exception $e) {
            $this->logger->log('Error fetching asset group count: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // get paginated asset groups
    public function getPaginatedAssetGroups($limit, $offset, $module, $username)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            $query = "SELECT * FROM ams_asset_group ORDER BY id DESC LIMIT $limit OFFSET $offset";
            $this->logger->logQuery($query, [], 'classes', $module, $username);
            return $this->conn->runQuery($query, []);
        } catch (Exception $e) {
            $this->logger->log('Error fetching paginated asset groups: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert asset group 
    public function insertAssetGroup($assetGroup, $createdBy, $module, $username)
    {
        try {
            $query = 'INSERT INTO ams_asset_group (asset_group, created_by) VALUES (?, ?)';
            $params = [$assetGroup, $createdBy];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset group '$assetGroup' inserted by user ID $createdBy";
            return $this->conn->insert($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error inserting asset group: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // insert batch asset groups from excel
    public function insertBatchAssetGroupsFromExcel($assetGroups, $createdBy)
    {
        if (empty($assetGroups) || !is_array($assetGroups)) {
            $this->logger->log('No asset groups to insert from excel or invalid format', 'classes', 'Excel Import', 'System');
            return false;
        }

        try {
            $query = 'INSERT INTO ams_asset_group (asset_group, created_by) VALUES (?, ?)';
            $params = [];
            foreach ($assetGroups as $assetGroup) {
                $params[] = [$assetGroup, $createdBy];
            }
            $this->logger->logQuery($query, $params, 'classes', 'Excel Import', 'System');
            return $this->conn->insertBatch($query, $params);
        } catch (Exception $e) {
            $this->logger->log('Error inserting batch asset groups from excel: ' . $e->getMessage(), 'classes', 'Excel Import', 'System');
            return false;
        }
    }

    public function getExistingAssetGroupsByNames(array $assetGroupsLower, $module, $username)
    {
        if (empty($assetGroupsLower)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($assetGroupsLower), '?'));
            $query = "SELECT LOWER(asset_group) AS asset_group FROM ams_asset_group WHERE LOWER(asset_group) IN ($placeholders)";
            $this->logger->logQuery($query, $assetGroupsLower, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $assetGroupsLower);
            return array_values(array_filter(array_map(function ($row) {
                return isset($row['asset_group']) ? strtolower($row['asset_group']) : null;
            }, $rows)));
        } catch (Exception $e) {
            $this->logger->log('Error fetching existing asset groups: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // get group ID by group name (case-insensitive)
    public function getGroupIdByName($groupName, $module, $username)
    {
        try {
            $query = 'SELECT id FROM ams_asset_group WHERE LOWER(TRIM(asset_group)) = LOWER(TRIM(?))';
            $params = [$groupName];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runSingle($query, $params);
            return $result ? (int)$result['id'] : null;
        } catch (Exception $e) {
            $this->logger->log('Error fetching group ID by name: ' . $e->getMessage(), 'classes', $module, $username);
            return null;
        }
    }

    // get multiple group IDs by group names (case-insensitive), returns associative array [normalized_name => id]
    public function getGroupIdsByNames(array $groupNames, $module, $username)
    {
        if (empty($groupNames)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($groupNames), '?'));
            $query = "SELECT id, LOWER(TRIM(asset_group)) AS normalized_name FROM ams_asset_group WHERE LOWER(TRIM(asset_group)) IN ($placeholders)";
            $this->logger->logQuery($query, $groupNames, 'classes', $module, $username);
            $rows = $this->conn->runQuery($query, $groupNames);
            
            $result = [];
            foreach ($rows as $row) {
                if (isset($row['normalized_name']) && isset($row['id'])) {
                    $result[$row['normalized_name']] = (int)$row['id'];
                }
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->log('Error fetching group IDs by names: ' . $e->getMessage(), 'classes', $module, $username);
            return [];
        }
    }

    // update asset group 
    public function updateAssetGroup($id, $assetGroup, $lastUpdatedBy, $module, $username)
    {
        try {
            $query = 'UPDATE ams_asset_group SET asset_group = ?, last_updated_by = ? WHERE id = ?';
            $params = [$assetGroup, $lastUpdatedBy, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset group ID $id updated to '$assetGroup' by user ID $lastUpdatedBy";
            return $this->conn->update($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error updating asset group: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // delete asset group
    public function deleteAssetGroup($id, $module, $username)
    {
        try {
            $query = 'DELETE FROM ams_asset_group WHERE id = ?';
            $params = [$id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $logMessage = "Asset group ID $id deleted by user $username";
            return $this->conn->delete($query, $params, $logMessage);
        } catch (Exception $e) {
            $this->logger->log('Error deleting asset group: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // helper methods

    // check duplicate assset group for insert
    public function isDuplicateGroup($assetGroup, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_group WHERE asset_group = ?';
            $params = [$assetGroup];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset group: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }

    // check duplicate asset group for update
    public function isDuplicateGroupForUpdate($id, $assetGroup, $module, $username)
    {
        try {
            $query = 'SELECT COUNT(*) AS total FROM ams_asset_group WHERE asset_group = ? AND id != ?';
            $params = [$assetGroup, $id];
            $this->logger->logQuery($query, $params, 'classes', $module, $username);
            $result = $this->conn->runQuery($query, $params);
            return isset($result[0]['total']) && (int)$result[0]['total'] > 0;
        } catch (Exception $e) {
            $this->logger->log('Error checking duplicate asset group for update: ' . $e->getMessage(), 'classes', $module, $username);
            return false;
        }
    }
}
