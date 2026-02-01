<?php
// Set timezone to UTC for consistent JWT expiry handling
date_default_timezone_set('UTC');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once(__DIR__ . '/../classes/DbController.php');
require_once(__DIR__ . '/../classes/authentication/JWTHandler.php');
require_once(__DIR__ . '/../classes/utils/GraphAutoMailer.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../classes/Logger.php');
require_once(__DIR__ . '/../classes/authentication/LoginUser.php');

use Dotenv\Dotenv;

// Load environment if present
$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
} else {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env');
}
$dotenv->load();

// Parse app config
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);

// initiate Logger

$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$module = 'ForgotPassword';


// Read input JSON
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim($input['email'] ?? '');
$entity_id = trim($input['entity_id'] ?? '');

// Only vendor portal supports password auth
$portal = 'vendor';

// Basic validation
if (!$email) {
    http_response_code(400);
    echo json_encode(["error" => "Email is required"]);
    exit();
}

if (!$entity_id || !is_numeric($entity_id)) {
    http_response_code(400);
    echo json_encode(["error" => "Valid entity_id is required"]);
    exit();
}


try {
    // Lookup user by email or username
    $db = new DBController();
    $user = $db->runSingle(
        "SELECT id, email, entity_id from tbl_users where (email = ? OR user_name = ?) AND entity_id = ? LIMIT 1",
        [$email, $email, $entity_id]
    );

    if(empty($user)){
        // User not found
        $logger->log("No account found with email: $email for entity_id: $entity_id", $module);
        http_response_code(400);
        echo json_encode([
            "error" => "No account found with that email for the specified entity"
        ]);
        exit();
    }

    if (!isset($user['email']) || !$user['email']) {
        // User not found
        $logger->log("No account found with email: $email", $module);
        http_response_code(400);
        echo json_encode([
            "error" => "No account found with that email"
        ]);
        exit();
    }

    if (empty($user['entity_id']) || !is_numeric($user['entity_id'])) {
        $logger->log("User record missing valid entity_id for email: " . $user['email'], $module);
        http_response_code(500);
        echo json_encode(["error" => "User record is invalid. Please contact support."]);
        exit();
    }

    $logger->log("Initiating password reset for user ID: " . $user['id'], $module);
    // Generate short-lived reset token (JWT)
    $jwt = new JWTHandler();
    $expiryMinutes = 30; // reset link expiry
    $token = $jwt->generateAccessToken([
        'sub' => $user['id'],
        'username' => $user['email'],
        'entity_id' => $user['entity_id'],
        'domain' => $portal,
        'iat' => time(),
        'exp' => time() + (60 * $expiryMinutes)
    ]);

    $loginUser = new UserLogin();
    $initiateStatus = $loginUser->initiateForgotPassword($user['id'], $user['entity_id'], $token, $expiryMinutes);
    if (!$initiateStatus) {
        $logger->log("Failed to initiate forgot password for user ID: " . $user['id'], $module);
        http_response_code(500);
        echo json_encode(["error" => "Failed to initiate password reset. Please try again later."]);
        exit();
    }


    // Build reset URL for vendor portal
    $vendorLoginUrl = $_ENV['VENDOR_LOGIN_URL'] ?? '';
    if (!$vendorLoginUrl) {
        http_response_code(500);
        echo json_encode(["error" => "Vendor login URL not configured"]);
        exit();
    }
    $resetBase = preg_replace('#/login$#', '/reset-password', $vendorLoginUrl);

    $resetLink = $resetBase . '?token=' . urlencode($token);

    $entityName = $db->runSingle(
        "SELECT entity_name FROM tbl_entity WHERE id = ? LIMIT 1",
        [$user['entity_id']]
    )['entity_name'] ?? 'your';

    // Send email via Microsoft Graph
    $mailer = new AutoMail();
    $subject = 'Password Reset Request';
    $greetings = 'Dear Vendor,';
    $name = $entityName ? $entityName : 'Shrichandra Group Team';
    // message to show the user that requested the password reset for the specific entity in the vendor portal
    $keyValueData = [
        'Message' => 'We received a request to reset the password for your vendor portal account under ' . $entityName . '. 
                  Please use the link below to create a new password. 
                  For security reasons, this link will expire in ' . $expiryMinutes . ' minutes. 
                  If you did not request this reset, please ignore this message.',
        'Email' => $user['email'],
        'Reset Link' => $resetLink,
        'Expires In' => $expiryMinutes . ' minutes'
    ];

    // Attempt to send; still return success regardless
    $emailSentStatus = $mailer->sendInfoEmail($subject, $greetings, $name, $keyValueData, [$user['email']]);

    if (!$emailSentStatus) {
        $logger->log("Failed to send reset email to: " . $user['email'], $module);
        http_response_code(400);
        echo json_encode(["error" => "Failed to send reset email. Please try again later."]);
        exit();
    }

    $logger->log("Password reset email sent to: " . $user['email'], $module);
    // Respond with success
    http_response_code(200);
    echo json_encode([
        'message' => 'A password reset link has been sent to your email.'
    ]);
    exit();
} catch (Throwable $e) {
    // Do not leak internals; return generic success
    $logger->log("Error during forgot password process: " . $e->getMessage(), $module);
    error_log('Forgot password error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        'message' => 'If the account exists, a reset link has been sent.'
    ]);
}
