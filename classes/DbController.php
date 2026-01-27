<?php

class DBController
{
    private $host;
    private $database;
    private $user;
    private $password;
    private PDO $conn;
    private $auth;
    private $userId;


    public function __construct()
    {
        // $ini_file_path = $_SERVER['DOCUMENT_ROOT'] . "/app.ini";
        $ini_file_path = __DIR__ . '/../app.ini';
        $ini_file = parse_ini_file($ini_file_path);

      
        $this->host = $ini_file["host"];
        $this->user = $ini_file["db_user"];
        $this->password = $ini_file["db_password"];
        $this->database = $ini_file["db_name"];
        $this->connectDB();
    }

    private function connectDB()
    {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->user, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function beginTrans()
    {
        $this->conn->beginTransaction();
    }

    public function commitTrans()
    {
        $this->conn->commit();
    }

    public function rollbackTrans()
    {
        $this->conn->rollback();
    }

    public function runQuery($query, $params = [])
    {
        try {
            $this->beginTrans();
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $this->commitTrans();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    public function runBaseQuery($query, $params = [])
    {
        try {
            $this->beginTrans();
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $this->commitTrans();
            return $stmt;
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    public function runSingle($query, $params = [])
    {
        try {
            $this->beginTrans();
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $this->commitTrans();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    public function insert($query, $params = [], $logMessage = '')
    {
        try {
            $this->beginTrans();
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $lastId = $this->conn->lastInsertId();

            if ($logMessage) {
                $logMessage .= " with ID: " . $lastId;
            } else {
                $logMessage = "New record inserted with ID: " . $lastId;
            }

            $this->logActivity($logMessage, $query);
            $this->commitTrans();
            return $lastId;
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    public function update($query, $params = [], $logMessage = '')
    {
        try {
            $this->beginTrans();
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->rowCount();

            if ($logMessage) {
                $logMessage .= " - Affected rows: $rows";
            } else {
                $logMessage = "Updated record(s) - Affected rows: $rows";
            }

            $this->logActivity($logMessage, $query);
            $this->commitTrans();
            return $rows;
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    public function delete($query, $params = [], $logMessage = '')
    {
        try {
            $this->beginTrans();
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->rowCount();

            if ($logMessage) {
                $logMessage .= " - Deleted rows: $rows";
            } else {
                $logMessage = "Deleted record(s) - Rows: $rows";
            }

            $this->logActivity($logMessage, $query);
            $this->commitTrans();
            return $rows;
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    public function buildSelectQuery(string $table, array $fields, array $allowedFields, string $orderBy = ''): ?string
    {
        try {
            $this->beginTrans();
            $selectedFields = array_intersect($fields, $allowedFields);
            if (empty($selectedFields)) {
                return null;
            }

            $fieldList = implode(', ', array_map(fn($field) => "`$field`", $selectedFields));
            $query = "SELECT $fieldList FROM `$table`";

            if (!empty($orderBy)) {
                $query .= " ORDER BY $orderBy";
            }

            $this->commitTrans();
            return $query;
        } catch (PDOException $ex) {
            $this->rollbackTrans();
            throw $ex;
        }
    }

    private function logActivity(string $message, string $query = '')
    {
        if (stripos($query, 'vw_activitylog') !== false) {
            return;
        }

        $auth = new UserLogin();
        $this->userId = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 0; // default to 0 if not found or initial or system
        $sql = "INSERT INTO vw_activitylog (`datetime`, `activity`, `log`, `user_id`)
                VALUES (NOW(), :activity, :log, :user_id)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':activity' => $message,
            ':log' => strlen($query) > 1000 ? substr($query, 0, 1000) : $query,
            ':user_id' => $this->userId
        ]);
    }
}
