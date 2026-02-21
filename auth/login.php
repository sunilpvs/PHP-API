<?php
// Set timezone to UTC for consistent JWT expiry handling
date_default_timezone_set('UTC');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_log("Raw JSON: " . file_get_contents("php://input"));

require_once(__DIR__ . '/../classes/authentication/LoginUser.php');
require_once(__DIR__ . "/../classes/authentication/JWTHandler.php");
require_once(__DIR__ . "/../vendor/autoload.php");

use Dotenv\Dotenv;


$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . "/../", ".env.prod");
} else {
    // 'C:\Users\BHASKARA TEJA\PVS Consultancy Services\PVS Sunil Babu - 04-PROJECTS\PHP\PHP_API\auth/../\.env'
    $dotenv = Dotenv::createImmutable(__DIR__ . "/../", ".env");
}


$dotenv->load();

$cookieDomain = $_ENV['COOKIE_DOMAIN'];
$url = $_ENV['MICROSOFT_REDIRECT_URI'];
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$portals = array_map('trim', explode(',', $config['portals']['portals']));

// echo "Setting cookie domain to: " . $cookieDomain . "\n";



$input = json_decode(file_get_contents('php://input'), true);

$portal = $_GET['portal'] ?? 'vendor';

// convert the portal to list of allowed domains for cookie
$allowedDomains = [$portal]; 

if (!isset($_GET['portal'])) {
    http_response_code(400);
    echo json_encode(["error" => "Portal parameter is required"]);
    exit();
}

if (!in_array($portal, $portals)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid portal specified"]);
    exit();
}


if ($portal === 'admin') {
    $portal = 'admin';
    http_response_code(400);
    echo json_encode(["error" => "You cant login to admin portal using username and password. Please use Microsoft SSO"]);
    exit();
}

if ($portal === 'vms') {
    $portal = 'vms';
    http_response_code(400);
    echo json_encode(["error" => "You cant login to vms portal using username and password. Please use Microsoft SSO"]);
    exit();
}

if ($portal === 'ams') {
    $portal = 'ams';
    http_response_code(400);
    echo json_encode(["error" => "You cant login to ams portal using username and password. Please use Microsoft SSO"]);
    exit();
}



$username = $input['username'] ?? '';
$password = $input['password'] ?? '';
$entity_id = $input['entity_id'] ?? '';
$reqType = $_GET['req'] ?? '';

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(["error" => "Username and password required"]);
    exit;
}

if (!$entity_id) {
    http_response_code(400);
    echo json_encode(["error" => "Entity ID is required"]);
    exit;
}


$user = new UserLogin();
$result = $user->validateUserLogin($username, $password, $entity_id);
$verify = $result[0];

if ($verify) {
    $row = $result[1];

    /**
     * ✅ Password Expiry Check
     * Make sure `password_last_changed_at` exists in your `users` table
     */
    // $expiryDays = 3;
    // $lastChanged = new DateTime($row['password_last_changed_at']);
    // $now = new DateTime();
    // $diffDays = $now->diff($lastChanged)->days;

    // if ($diffDays > $expiryDays) {
    //     http_response_code(403);
    //     echo json_encode([
    //         "error" => "Password expired. Please reset your password."
    //     ]);
    //     exit;
    // }


    $payload = [
        "username" => $row['email'],
        "sub" => $row['id'],
        "allowed_domains" => $allowedDomains,
        "iat" => time(),
        "exp" => time() + (60 * 100) // Access token valid for 100 minutes
    ];


    $jwt = new JWTHandler();

    $access_token = $jwt->generateAccessToken([
        "sub" => $row['id'],
        "username" => $row['email'],
        "allowed_domains" => $allowedDomains,
        "iat" => time(),
        "exp" => time() + (60 * 100) // Access token valid for 100 minutes
    ]);



    $refreshToken = $jwt->generateRefreshToken([
        "sub" => $row['id'],
        "username" => $row['email'],
        "allowed_domains" => $allowedDomains,
        "iat" => time(),
        "exp" => time() + (60 * 60 * 24 * 7) // Refresh token valid for 7 days
    ]);



    // echo $access_token;
    // echo $refreshToken;




    $responseData = [
        "message" => "Login successful",
    ];

    if ($reqType === 'token') {
        $responseData["access_token"] = $access_token;
        $responseData["refresh_token"] = $refreshToken;
    } else {

        //  set access token, refresh token and domain as cookie domain
        setcookie(
            "access_token",
            $access_token,
            [
                "expires" => time() + (60 * 100),
                "path" => "/",
                "secure" => true,
                "domain" => $cookieDomain,
                "httponly" => true,
                "samesite" => "None",
            ]
        );

        setcookie(
            "refresh_token",
            $refreshToken,
            [
                "expires" => time() + (60 * 60 * 24 * 7),
                "path" => "/",
                "secure" => true,
                "domain" => $cookieDomain,
                "httponly" => true,
                "samesite" => "None"
            ]
        );
    }

    http_response_code(200);
    echo json_encode($responseData);
} else {
    http_response_code(401);
    echo json_encode(["error" => "Invalid credentials"]);
}
