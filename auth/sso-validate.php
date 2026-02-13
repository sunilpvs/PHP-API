<?php


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . '/../classes/authentication/JWTHandler.php');
require_once(__DIR__ . '/../classes/DbController.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use Dotenv\Dotenv;

$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
} else {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env');
}
$dotenv->load();

header('Content-Type: application/json');

// Get portal from request
$portal = strtolower($_GET['portal'] ?? null);


if (!$portal) {
    http_response_code(400);
    echo json_encode([
        "authenticated" => false,
        "message" => "Portal parameter is required"
    ]);
    exit;
}

// Get access token from cookies or Authorization header
$accessToken = null;

$headers = getallheaders();
if (isset($headers['Authorization'])) {
    $parts = explode(' ', $headers['Authorization']);
    if (count($parts) === 2 && $parts[0] === 'Bearer') {
        $accessToken = $parts[1];
    }
}

if (!$accessToken && isset($_COOKIE['access_token'])) {
    $accessToken = $_COOKIE['access_token'];
}

if (!$accessToken) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "message" => "No access token found"
    ]);
    exit;
}

// Verify token (uses existing payload structure)
$jwtHandler = new JWTHandler();
$conn = new DBController();
$tokenData = $jwtHandler->verifyToken($accessToken);

if (!$tokenData) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "message" => "Invalid or expired token"
    ]);
    exit;
}


$userId = $tokenData->sub ?? null;
$username = $tokenData->username ?? null;
$domain = $tokenData->domain ?? null;       // Current portal from token

if (!$userId || !$username) {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "message" => "Invalid token structure"
    ]);
    exit;
}

// Check if user has access to requested portal
$dbObject = new DbController();

// Query user access status from your database
// Adjust table/column names to match your schema
$query = "SELECT 1
            WHERE 
                EXISTS (
                    SELECT 1
                    FROM tbl_access_requests ar
                    WHERE ar.email = ?
                    AND ar.status = 11
                )
            AND EXISTS (
                    SELECT 1
                    FROM tbl_user_modules um
                    JOIN tbl_module m 
                        ON um.module_id = m.module_id
                    WHERE LOWER(m.module_name) = ?
                    AND um.email = ?
                )
            AND EXISTS (
                    SELECT 1
                    FROM tbl_users u
                    WHERE u.email = ?
                )";

$result = $conn->runQuery($query, [$userId, $portal, $userId, $userId]);


if (count($result) > 0) {
    // User is approved for this portal
    $userAccess = $result[0];

    http_response_code(200);
    echo json_encode([
        "authenticated" => true,
        "message" => "User is authenticated and approved for this portal",
        "user_id" => $userId,
        "username" => $username,  // Return email for VMS to use
        "portal" => $portal,
        "role" => $userAccess['role']
    ]);
} else {
    // User doesn't have approval yet
    http_response_code(403);
    echo json_encode([
        "authenticated" => false,
        "message" => "User not approved for this portal. Please contact admin.",
        "user_id" => $userId
    ]);
}

exit;
