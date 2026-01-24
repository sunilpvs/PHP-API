<?php
// Set timezone to UTC for consistent JWT expiry handling
date_default_timezone_set('UTC');

// CORS headers
// header("Access-Control-Allow-Origin: http://localhost:3000");
// header("Access-Control-Allow-Credentials: true");
// header("Access-Control-Allow-Headers: Content-Type, Authorization");
// header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . "/vendor/autoload.php");
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/JWTHandler.php');

use Dotenv\Dotenv;


$env = getenv('APP_ENV') ?: 'local';

if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
} else {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env');
}
$dotenv->load();

$cookieDomain = $_ENV['COOKIE_DOMAIN'];

$jwt = new JWTHandler();
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$portals = array_map('trim', explode(',', $config['portals']['portals']));

$middleware_portal = $_GET['portal'] ?? '';

if (!isset($middleware_portal)) {
    http_response_code(400);
    echo json_encode(["error" => "Portal parameter is required"]);
    exit();
}

if (!in_array($middleware_portal, $portals)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid portal specified"]);
    exit();
}

$refreshToken = $_COOKIE['refresh_token'] ?? '';


if (!$refreshToken) {
    http_response_code(401);
    echo json_encode(["error" => "No refresh token found"]);
    exit();
}



$verified = $jwt->verifyToken($refreshToken);
if (!$verified) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid refresh token"]);
    exit();
}

if ($verified->domain !== $middleware_portal) {
    http_response_code(401);
    echo json_encode(["error" => "Refresh token does not match the portal"]);
    exit();
}


$username = $verified->username;


$newAccessToken = $jwt->generateAccessToken([
    "sub" => $verified->sub,
    "username" => $username,
    "domain" => $middleware_portal,
    "iat" => time(),
    "exp" => time() + (60 * 15) // Access token valid for 15 minutes
]);

setcookie(
    "access_token",
    $newAccessToken,
    [
        "expires" => time() + (60 * 15),
        "path" => "/",
        "secure" => true,
        "domain" => $cookieDomain,
        "httponly" => true,
        "samesite" => "None",
    ]
);

echo json_encode([
    "message" => "Access token refreshed"
]);
