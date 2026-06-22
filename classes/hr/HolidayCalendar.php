<?php
// This file is to add/update/delete holidays in the 
// holiday calendar table in the database.

// CREATE TABLE hr_branches (
//     branch_id INT AUTO_INCREMENT PRIMARY KEY,
//     branch_name VARCHAR(100) NOT NULL UNIQUE,
//     is_active TINYINT(1) NOT NULL DEFAULT 1,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


// CREATE TABLE hr_holiday_calendar (
//     holiday_id INT AUTO_INCREMENT PRIMARY KEY,
//     holiday_name VARCHAR(255) NOT NULL,
//     holiday_date DATE NOT NULL,
//     description TEXT,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

//     UNIQUE KEY uq_holiday_date_name (holiday_name, holiday_date)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


// CREATE TABLE hr_holiday_branch_mapping (
//     holiday_id INT NOT NULL,
//     branch_id INT NOT NULL,

//     PRIMARY KEY (holiday_id, branch_id),

//     CONSTRAINT fk_holiday_branch_holiday
//         FOREIGN KEY (holiday_id)
//         REFERENCES hr_holiday_calendar (holiday_id)
//         ON DELETE CASCADE,

//     CONSTRAINT fk_holiday_branch_branch
//         FOREIGN KEY (branch_id)
//         REFERENCES hr_branches (branch_id)
//         ON DELETE CASCADE
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


// INSERT INTO hr_branches (branch_name) VALUES
// ('Gujarat'),
// ('Andhra Pradesh'),
// ('Telangana'),
// ('Tamil Nadu'),
// ('Delhi'),
// ('Maharashtra');


require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';

class HolidayCalendar
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

    private function normalizeBranchList($branches)
    {
        if (is_array($branches)) {
            $items = $branches;
        } else {
            $items = explode(',', (string)$branches);
        }

        $items = array_map('trim', $items);
        $items = array_values(array_filter($items, static fn($value) => $value !== ''));
        return array_values(array_unique($items));
    }

    private function getAllowedBranchMap($module, $username)
    {
        $query = 'SELECT branch_id, branch_name FROM hr_branches WHERE is_active = 1 ORDER BY branch_name';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $rows = $this->conn->runQuery($query);

        $map = [];
        foreach ($rows as $row) {
            $map[strtolower(trim((string)$row['branch_name']))] = [
                'branch_id' => (int)$row['branch_id'],
                'branch_name' => $row['branch_name'],
            ];
        }

        return $map;
    }

    private function getBranchIdByName($branchName, $module, $username)
    {
        $query = 'SELECT branch_id FROM hr_branches WHERE branch_name = ? AND is_active = 1';
        $this->logger->logQuery($query, [$branchName], 'classes', $module, $username);
        $result = $this->conn->runQuery($query, [$branchName]);
        return isset($result[0]['branch_id']) ? (int)$result[0]['branch_id'] : null;
    }

    private function getBranchNamesForHolidayIds(array $holidayIds, $module, $username)
    {
        if (empty($holidayIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($holidayIds), '?'));
        $query = "SELECT m.holiday_id, b.branch_name
                  FROM hr_holiday_branch_mapping m
                  INNER JOIN hr_branches b ON b.branch_id = m.branch_id
                  WHERE m.holiday_id IN ($placeholders)
                  ORDER BY b.branch_name";
        $this->logger->logQuery($query, $holidayIds, 'classes', $module, $username);
        $rows = $this->conn->runQuery($query, $holidayIds);

        $mapped = [];
        foreach ($rows as $row) {
            $holidayId = (int)$row['holiday_id'];
            $mapped[$holidayId][] = $row['branch_name'];
        }

        return $mapped;
    }

    private function attachBranches(array $rows, $module, $username)
    {
        if (empty($rows)) {
            return [];
        }

        $holidayIds = array_map(static fn($row) => (int)$row['holiday_id'], $rows);
        $branchMap = $this->getBranchNamesForHolidayIds($holidayIds, $module, $username);

        foreach ($rows as &$row) {
            $holidayId = (int)$row['holiday_id'];
            $row['branches'] = $branchMap[$holidayId] ?? [];
        }

        return $rows;
    }

    public function getAllHolidays($module, $username)
    {
        $query = 'SELECT holiday_id, holiday_name, holiday_date, description, created_at FROM hr_holiday_calendar ORDER BY holiday_date DESC, holiday_id DESC';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $rows = $this->conn->runQuery($query);
        return $this->attachBranches($rows, $module, $username);
    }

    public function getPaginatedHolidays($offset, $limit, $module, $username)
    {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $query = "SELECT holiday_id, holiday_name, holiday_date, description, created_at
                  FROM hr_holiday_calendar
                  ORDER BY holiday_date DESC, holiday_id DESC
                  LIMIT $limit OFFSET $offset";
        $this->logger->logQuery($query, [$limit, $offset], 'classes', $module, $username);
        $rows = $this->conn->runQuery($query, []);
        return $this->attachBranches($rows, $module, $username);
    }

    public function getAllBranches($module, $username)
    {
        $query = 'SELECT branch_id, branch_name FROM hr_branches WHERE is_active = 1 ORDER BY branch_name';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        return $this->conn->runQuery($query);
    }

    public function getHolidaysCount($module, $username)
    {
        $query = 'SELECT COUNT(*) AS total FROM hr_holiday_calendar';
        $this->logger->logQuery($query, [], 'classes', $module, $username);
        $result = $this->conn->runQuery($query);
        return isset($result[0]['total']) ? (int)$result[0]['total'] : 0;
    }

    public function getHolidayById($id, $module, $username)
    {
        $query = 'SELECT holiday_id, holiday_name, holiday_date, description, created_at FROM hr_holiday_calendar WHERE holiday_id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        $result = $this->conn->runQuery($query, [$id]);
        if (empty($result[0])) {
            return null;
        }

        $rows = $this->attachBranches($result, $module, $username);
        return $rows[0];
    }

    public function getHolidaysByBranch($branch, $module, $username)
    {
        $query = "SELECT h.holiday_id, h.holiday_name, h.holiday_date, h.description, h.created_at
                  FROM hr_holiday_calendar h
                  INNER JOIN hr_holiday_branch_mapping m ON m.holiday_id = h.holiday_id
                  INNER JOIN hr_branches b ON b.branch_id = m.branch_id
                  WHERE b.branch_name = ?
                  ORDER BY h.holiday_date DESC, h.holiday_id DESC";
        $this->logger->logQuery($query, [$branch], 'classes', $module, $username);
        $rows = $this->conn->runQuery($query, [$branch]);

        $branchMap = $this->getBranchNamesForHolidayIds(array_map(static fn($row) => (int)$row['holiday_id'], $rows), $module, $username);
        foreach ($rows as &$row) {
            $holidayId = (int)$row['holiday_id'];
            $row['branches'] = $branchMap[$holidayId] ?? [];
        }

        return $rows;
    }

    public function getHolidaysByMonthAndYear($month, $year, $module, $username)
    {
        $query = 'SELECT holiday_id, holiday_name, holiday_date, description, created_at FROM hr_holiday_calendar WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ? ORDER BY holiday_date DESC, holiday_id DESC';
        $this->logger->logQuery($query, [$month, $year], 'classes', $module, $username);
        $rows = $this->conn->runQuery($query, [$month, $year]);
        return $this->attachBranches($rows, $module, $username);
    }

    public function isDuplicateBranchDate($branchName, $holidayDate, $module, $username, $excludeHolidayId = null)
    {
        $query = "SELECT 1
                  FROM hr_holiday_calendar h
                  INNER JOIN hr_holiday_branch_mapping m ON m.holiday_id = h.holiday_id
                  INNER JOIN hr_branches b ON b.branch_id = m.branch_id
                  WHERE b.branch_name = ? AND h.holiday_date = ?";
        $params = [$branchName, $holidayDate];
        if ($excludeHolidayId !== null) {
            $query .= ' AND h.holiday_id <> ?';
            $params[] = $excludeHolidayId;
        }
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runQuery($query, $params);
        return !empty($result);
    }

    public function getExistingBranchDatePairs(array $pairs, $module, $username)
    {
        if (empty($pairs)) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($pairs as $pair) {
            $conditions[] = '(b.branch_name = ? AND h.holiday_date = ?)';
            $params[] = $pair['branches'];
            $params[] = $pair['holiday_date'];
        }

        $query = 'SELECT b.branch_name, h.holiday_date
                  FROM hr_holiday_calendar h
                  INNER JOIN hr_holiday_branch_mapping m ON m.holiday_id = h.holiday_id
                  INNER JOIN hr_branches b ON b.branch_id = m.branch_id
                  WHERE ' . implode(' OR ', $conditions);
        $this->logger->logQuery($query, $params, 'classes', $module, $username);
        $result = $this->conn->runQuery($query, $params);

        $existing = [];
        foreach ($result as $row) {
            $key = strtolower(trim((string)$row['branch_name'])) . '|' . $row['holiday_date'];
            $existing[$key] = true;
        }

        return $existing;
    }

    public function addHoliday($holidayName, $holidayDate, $branches, $description, $module, $username)
    {
        $branchList = $this->normalizeBranchList($branches);
        if (empty($branchList)) {
            return [
                'success' => false,
                'message' => 'No branches provided',
                'holiday_id' => null,
                'inserted_branches' => [],
                'skipped_branches' => [],
            ];
        }

        $allowedBranchMap = $this->getAllowedBranchMap($module, $username);
        $insertedBranches = [];
        $skippedBranches = [];

        foreach ($branchList as $branch) {
            $lookup = strtolower(trim((string)$branch));
            if (!isset($allowedBranchMap[$lookup])) {
                $skippedBranches[] = [
                    'branch' => $branch,
                    'reason' => 'Invalid branch',
                ];
                continue;
            }

            $branchName = $allowedBranchMap[$lookup]['branch_name'];
            if ($this->isDuplicateBranchDate($branchName, $holidayDate, $module, $username)) {
                $skippedBranches[] = [
                    'branch' => $branchName,
                    'reason' => 'Duplicate branch and date already exists',
                ];
                continue;
            }

            $insertedBranches[] = $allowedBranchMap[$lookup];
        }

        if (empty($insertedBranches)) {
            return [
                'success' => false,
                'message' => 'All branches were skipped',
                'holiday_id' => null,
                'inserted_branches' => [],
                'skipped_branches' => $skippedBranches,
            ];
        }

        $query = 'INSERT INTO hr_holiday_calendar (holiday_name, holiday_date, description) VALUES (?, ?, ?)';
        $this->logger->logQuery($query, [$holidayName, $holidayDate, $description], 'classes', $module, $username);
        $holidayId = $this->conn->insert($query, [$holidayName, $holidayDate, $description], 'Added holiday: ' . $holidayName);

        foreach ($insertedBranches as $branchInfo) {
            $mapQuery = 'INSERT INTO hr_holiday_branch_mapping (holiday_id, branch_id) VALUES (?, ?)';
            $this->logger->logQuery($mapQuery, [$holidayId, $branchInfo['branch_id']], 'classes', $module, $username);
            $this->conn->insert($mapQuery, [$holidayId, $branchInfo['branch_id']], 'Mapped holiday to branch');
        }

        return [
            'success' => true,
            'message' => 'Holiday added successfully',
            'holiday_id' => (int)$holidayId,
            'inserted_branches' => array_map(static fn($row) => $row['branch_name'], $insertedBranches),
            'skipped_branches' => $skippedBranches,
        ];
    }

    public function addHolidayBatch(array $rows, $module, $username)
    {
        if (empty($rows)) {
            return [
                'success' => true,
                'inserted' => 0,
                'skipped_rows' => [],
                'results' => [],
            ];
        }

        $inserted = 0;
        $results = [];
        $skippedRows = [];

        foreach ($rows as $row) {
            $result = $this->addHoliday($row['holiday_name'], $row['holiday_date'], $row['branches'], $row['description'], $module, $username);
            $results[] = $result;
            if (!empty($result['success'])) {
                $inserted++;
            } else {
                $skippedRows[] = $row;
            }
        }

        return [
            'success' => true,
            'inserted' => $inserted,
            'skipped_rows' => $skippedRows,
            'results' => $results,
        ];
    }

    public function updateHoliday($id, $holidayName, $holidayDate, $branches, $description, $module, $username)
    {
        $branchList = $this->normalizeBranchList($branches);
        if (empty($branchList)) {
            return [
                'success' => false,
                'message' => 'No branches provided',
                'holiday_id' => $id,
                'inserted_branches' => [],
                'skipped_branches' => [],
            ];
        }

        $allowedBranchMap = $this->getAllowedBranchMap($module, $username);
        $insertedBranches = [];
        $skippedBranches = [];
        foreach ($branchList as $branch) {
            $lookup = strtolower(trim((string)$branch));
            if (!isset($allowedBranchMap[$lookup])) {
                $skippedBranches[] = [
                    'branch' => $branch,
                    'reason' => 'Invalid branch',
                ];
                continue;
            }

            $branchName = $allowedBranchMap[$lookup]['branch_name'];
            if ($this->isDuplicateBranchDate($branchName, $holidayDate, $module, $username, $id)) {
                $skippedBranches[] = [
                    'branch' => $branchName,
                    'reason' => 'Duplicate branch and date already exists',
                ];
                continue;
            }

            $insertedBranches[] = $allowedBranchMap[$lookup];
        }

        if (empty($insertedBranches)) {
            return [
                'success' => false,
                'message' => 'All branches were skipped',
                'holiday_id' => $id,
                'inserted_branches' => [],
                'skipped_branches' => $skippedBranches,
            ];
        }

        $query = 'UPDATE hr_holiday_calendar SET holiday_name = ?, holiday_date = ?, description = ? WHERE holiday_id = ?';
        $this->logger->logQuery($query, [$holidayName, $holidayDate, $description, $id], 'classes', $module, $username);
        $rows = $this->conn->update($query, [$holidayName, $holidayDate, $description, $id], 'Updated holiday with ID: ' . $id);

        $deleteMapQuery = 'DELETE FROM hr_holiday_branch_mapping WHERE holiday_id = ?';
        $this->logger->logQuery($deleteMapQuery, [$id], 'classes', $module, $username);
        $this->conn->delete($deleteMapQuery, [$id], 'Cleared branch mappings for holiday ' . $id);

        foreach ($insertedBranches as $branchInfo) {
            $mapQuery = 'INSERT INTO hr_holiday_branch_mapping (holiday_id, branch_id) VALUES (?, ?)';
            $this->logger->logQuery($mapQuery, [$id, $branchInfo['branch_id']], 'classes', $module, $username);
            $this->conn->insert($mapQuery, [$id, $branchInfo['branch_id']], 'Mapped holiday to branch');
        }

        return [
            'success' => true,
            'updated_rows' => (int)$rows,
            'holiday_id' => (int)$id,
            'inserted_branches' => array_map(static fn($row) => $row['branch_name'], $insertedBranches),
            'skipped_branches' => $skippedBranches,
        ];
    }

    public function deleteHoliday($id, $module, $username)
    {
        $deleteMapQuery = 'DELETE FROM hr_holiday_branch_mapping WHERE holiday_id = ?';
        $this->logger->logQuery($deleteMapQuery, [$id], 'classes', $module, $username);
        $this->conn->delete($deleteMapQuery, [$id], 'Deleted holiday mappings with ID: ' . $id);

        $query = 'DELETE FROM hr_holiday_calendar WHERE holiday_id = ?';
        $this->logger->logQuery($query, [$id], 'classes', $module, $username);
        return $this->conn->delete($query, [$id], 'Deleted holiday with ID: ' . $id);
    }

}
