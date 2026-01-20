<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



require_once __DIR__ . '/../DbController.php';
require_once(__DIR__ . "/JWTHandler.php");

class UserLogin
{
    private $conn;
    private $jwt;


    function __construct()
    {
        $this->conn = new DBController();
        $this->jwt = new JWTHandler();
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

    function resetPassword($userId, $newPassword)
    {
        $query = 'UPDATE tbl_users SET password_last_changed_at = ? WHERE id = ?';
        // Log the query with parameters
        // $logMessage = 'Password Reset ';
        return $this->conn->update($query, [$newPassword, $userId]);
    }
}
