<?php



require_once __DIR__ . '/../DbController.php';
require_once(__DIR__ . "/JWTHandler.php");
require_once(__DIR__ . "/../Logger.php");

class UserLogin
{
    private $conn;
    private $jwt;
    private $logger;


    function __construct()
    {
        $this->conn = new DBController();
        $this->jwt = new JWTHandler();
        $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $this->logger = new Logger($debugMode, $logDir);
    }

    function validateUserLogin($username, $password)
    {
        $sql = "SELECT a.id, a.user_name, a.password, a.code, a.status, b.f_name , b.l_name, a.email, b.mobile, c.Name as ctype, d.user_role, a.entity_id ";
        $sql .= "FROM tbl_users a, tbl_contact b, tbl_contacttype c, tbl_user_role d ";
        $sql .= " WHERE a.contact_Id = b.Id AND b.contacttype_Id = c.id AND a.user_status = 1";
        $sql .= " AND (a.user_name = ? OR a.email = ?) LIMIT 1";
        // Run the query using the DBController's runSingle method
        $result = $this->conn->runSingle($sql, [$username, $username]);

        if (!$result) {
            // User not found
            return [0, 0];
        }


        $hash = $result['password'];
        $verify = password_verify($password, $hash);
        return [$verify, $result];
    }

    function getAllUserDetails($username)
    {
        $result = $this->conn->runSingle(
            "SELECT * FROM vw_userprofile WHERE user_name = ? OR email = ? ORDER BY 
                                                        CASE
                                                            WHEN user_role = 'IT ADMIN' THEN 1
                                                            WHEN user_role = 'SUPER USER' THEN 2
                                                            WHEN user_role = 'VMS_ADMIN' THEN 3
                                                            WHEN user_role = 'VMS_MANAGEMENT' THEN 4
                                                            WHEN user_role = 'VMS_VENDOR' THEN 5
                                                            WHEN user_role = 'BASE_EMPLOYEE' THEN 6
                                                            ELSE 7
                                                        END
                                                    LIMIT 1",
            [$username, $username]
        );

        return $result;
    }



    function getUserData($row)
    {
        $ini_file_path = $_SERVER['DOCUMENT_ROOT'] . "/app.ini";
        $ini_file = parse_ini_file($ini_file_path);
        $app_url = $ini_file["app_url"];
        $logo = $ini_file["logo"];

        return [
            'user_name' => $row["user_name"],
            'f_name' => $row["f_name"],
            'l_name' => $row["l_name"],
            'mobile' => $row["mobile"],
            'dob' => $row["dob"],
            'address1' => $row["add1"],
            'address2' => $row["add2"],
            'city' => $row["city"],
            'state' => $row["state"],
            'country' => $row["country"],
            'email' => $row["email"],
            'personal_email' => $row["personal_email"],
            'status' => $row["status"],
            'user_role' => $row["user_role"],
            'joining_date' => $row["join_date"],
            'exit_date' => $row["exit_date"],
            'department' => $row["dept"],
            'designation' => $row["desig"],

        ];
    }

    function getToken()
    {

        $token = $_COOKIE['access_token'] ?? '';

        if (!$token) {
            $headers = getallheaders();
            if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                $token = $matches[1];
            }
        }
        return $token;
    }

    function getUserIdFromJWT()
    {
        $token = $this->getToken(); // Retrieve the token


        // if token is not found, return error 
        if (!$token) {
            return null;
            http_response_code(401);
            echo json_encode(["error" => "Token not found"]);
        }

        $decodedToken = $this->jwt->decodeJWT($token);

        $userId = $decodedToken['sub'] ?? null;

        if ($userId) {
            return $userId;
        } else {
            http_response_code(401);
            echo json_encode(["error" => "User ID not found in token"]);
            exit;
        }
    }

    function getEmailFromJWT()
    {
        $token = $this->getToken(); // Retrieve the token

        if (!$token) {
            http_response_code(401);
            echo json_encode(["error" => "Token not found"]);
            exit;
        }

        $decodedToken = $this->jwt->decodeJWT($token);

        $email = $decodedToken['username'] ?? null;

        if ($email) {
            return $email;
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Email not found in token"]);
            exit;
        }
    }

    function getPortalFromJWT()
    {
        $token = $this->getToken(); // Retrieve the token

        if (!$token) {
            http_response_code(401);
            echo json_encode(["error" => "Token not found"]);
            exit;
        }

        $decodedToken = $this->jwt->decodeJWT($token);

        $portal = $decodedToken['domain'] ?? null;

        if ($portal) {
            return $portal;
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Portal not found in token"]);
            exit;
        }
    }

    function initiateForgotPassword($userId, $token, $expiryMinutes)
    {
        $query = 'UPDATE tbl_password_resets
                    SET used_at = NOW()
                    WHERE user_id = ?
                    AND used_at IS NULL';
        $params = [$userId];
        $this->logger->logQuery($query, $params, 'Invalidate Previous Password Reset Tokens');
        $this->conn->update($query, $params);

        $query = 'INSERT INTO tbl_password_resets(user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))';
        $params = [$userId, hash('sha256', $token), $expiryMinutes];
        $this->logger->logQuery($query, $params, 'Initiate Forgot Password');
        $result = $this->conn->insert($query, $params);
        if ($result > 0) {
            return true;
        }
        return false;
    }

    function changePassword($userId, $newPassword, $resetRecordId)
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $query = "UPDATE tbl_users SET password = ? WHERE id = ?";
        $params = [$hashedPassword, $userId];
        $this->logger->logQuery($query, $params, 'Change Password');
        $changePasswordResult = $this->conn->update($query, $params);



        // update tbl_password_resets to mark the token as used
        $query = "UPDATE tbl_password_resets SET used_at = NOW() WHERE id=?";
        $params = [$resetRecordId];
        $this->logger->logQuery($query, $params, 'Mark Password Reset Token as Used');
        $markTokenUsedResult = $this->conn->update($query, $params);

        if ($changePasswordResult > 0 && $markTokenUsedResult > 0) {
            return true;
        }
        return false;
    }

    function getValidPasswordResetRecord($userId, $token)
    {
        $tokenHash = hash('sha256', $token);

        $query = "
        SELECT id
        FROM tbl_password_resets
        WHERE user_id = ?
          AND token_hash = ?
          AND used_at IS NULL
          AND expires_at > NOW()
        LIMIT 1
    ";

        return $this->conn->runSingle($query, [$userId, $tokenHash]);
    }
}
