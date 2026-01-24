<?php
// Set timezone to UTC for consistent JWT expiry handling
date_default_timezone_set('UTC');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once(__DIR__ . '/../classes/authentication/middle.php');
require_once(__DIR__ . '/../classes/authentication/LoginUser.php');
require_once(__DIR__ . '/../classes/Logger.php');

use Dotenv\Dotenv;
// Load environment if present
$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
} else {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env');
}
$dotenv->load();

$input = json_decode(file_get_contents("php://input"), true);
$logger = new Logger(
    isset($_ENV['DEBUG_MODE']) && in_array(strtolower($_ENV['DEBUG_MODE']), ['1', 'true'], true),
    $_SERVER['DOCUMENT_ROOT'] . '/logs'
);
 
// ✅ authenticate user by JWT token from reset link
$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(400);
    echo json_encode(["error" => "Token is required"]);
    exit;
}

$logger->log("Received password reset request with token", "ResetPassword");

$jwtHandler = new JWTHandler();
$decodedToken = $jwtHandler->decodeJWT($token);
if (!$decodedToken) {
    $logger->log("Invalid or expired token used for password reset", "ResetPassword");
    http_response_code(400);
    echo json_encode(["error" => "Invalid or expired token"]);
    exit;
}

if ($decodedToken['domain'] !== 'vendor') {
    $logger->log("Invalid reset domain: " . $decodedToken['domain'], "ResetPassword");
    http_response_code(403);
    echo json_encode(["error" => "Invalid reset domain"]);
    exit;
}


$userId = $decodedToken['sub'] ?? null;
if (!$userId) {
    $logger->log("User ID not found in token payload", "ResetPassword");
    http_response_code(400);
    echo json_encode(["error" => "Invalid token payload"]);
    exit;
}

$logger->log("Token validated for user ID: $userId", "ResetPassword");
$newPassword = $input['new_password'] ?? null;
 
if (!$newPassword) {
    http_response_code(400);
    echo json_encode(["error" => "New password required"]);
    exit;
}

if (
    strlen($newPassword) < 8 ||
    !preg_match('/[A-Z]/', $newPassword) ||
    !preg_match('/[a-z]/', $newPassword) ||
    !preg_match('/[0-9]/', $newPassword) ||
    !preg_match('/[\W]/', $newPassword)
) {
    http_response_code(400);
    echo json_encode(["error" => "Password does not meet complexity requirements. 
                It must be at least 8 characters long and include uppercase letters, 
                lowercase letters, numbers, and special characters."
            ]);
    exit;
}

// initiate user login class
$user = new UserLogin();

// verify password reset record
$resetRecord = $user->getValidPasswordResetRecord($userId, $token);
if (!$resetRecord) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid or expired reset link"]);
    exit;
}


 
// update DB

$status = $user->changePassword($userId, $newPassword, $resetRecord['id']);

if(!$status){
    $logger->log("Failed to reset password for user ID: $userId", "ResetPassword");
    http_response_code(500);
    echo json_encode([
        "error" => "Failed to reset password"
    ]);
    exit;
}

$logger->log("Password reset successfully for user ID: $userId", "ResetPassword");
http_response_code(200);
echo json_encode([
    "message" => "Password has been reset successfully"
]);
exit();
 
