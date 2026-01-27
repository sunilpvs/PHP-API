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

// Only vendor portal supports password auth
$portal = 'vendor';

// Basic validation
if (!$email) {
    http_response_code(400);
    echo json_encode(["error" => "Email is required"]);
    exit();
}

try {
    // Lookup user by email or username
    $db = new DBController();
    $user = $db->runSingle(
        "SELECT id, email from tbl_users where email = ? OR user_name = ? LIMIT 1",
        [$email, $email]
    );

    if (empty($user) || !isset($user['email']) || !$user['email']) {
        // User not found
        $logger->log("No account found with email: $email", $module);
        http_response_code(400);
        echo json_encode([
            "error" => "No account found with that email"
        ]);
        exit();
    }
    
    $logger->log("Initiating password reset for user ID: " . $user['id'], $module);
    // Generate short-lived reset token (JWT)
    $jwt = new JWTHandler();
    $expiryMinutes = 30; // reset link expiry
    $token = $jwt->generateAccessToken([
        'sub' => $user['id'],
        'username' => $user['email'],
        'domain' => $portal,
        'iat' => time(),
        'exp' => time() + (60 * $expiryMinutes)
    ]);

    $loginUser = new UserLogin();
    $initiateStatus = $loginUser->initiateForgotPassword($user['id'], $token, $expiryMinutes);
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

    // Send email via Microsoft Graph
    $mailer = new AutoMail();
    $subject = 'Password Reset Request';
    $greetings = 'Dear Vendor,';
    $name = "Shrichandra Group Team";
    $keyValueData = [
        'Message' => 'We received a request to reset your password. 
                    Please use the link below to set a new password. 
                    This link will expire in ' . $expiryMinutes . ' minutes.',
        'Email' => $user['email'],
        'Reset Link' => $resetLink,
        'Expires In' => $expiryMinutes . ' minutes'
    ];

    // Attempt to send; still return success regardless
    $emailSentStatus = $mailer->sendInfoEmail($subject, $greetings, $name, $keyValueData, [$user['email']]);

    if(!$emailSentStatus) {
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
?>