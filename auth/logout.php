<?php
// header("Access-Control-Allow-Origin: http://localhost:5173");
// header("Access-Control-Allow-Methods: POST, OPTIONS");
// header("Access-Control-Allow-Headers: Content-Type, Authorization");
// header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
require_once(__DIR__ . "/../vendor/autoload.php");

use Dotenv\Dotenv;


$env = getenv('APP_ENV') ?: 'local';
if($env === 'production'){
    $dotenv = Dotenv::createImmutable(__DIR__ . "/../", ".env.prod");
}else{
    $dotenv= Dotenv::createImmutable(__DIR__ . "/../", ".env");
}


$dotenv->load();

$cookieDomain = $_ENV['COOKIE_DOMAIN'];


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Removing access token
setcookie("access_token", "", [
  "expires" => time() - 3600,
  "path" => "/",
  "domain" => $cookieDomain,
  "secure" => true,
  "httponly" => true,
  "samesite" => "None"
]);
// Removing refresh token
setcookie("refresh_token", "", [
  "expires" => time() - 3600,
  "path" => "/",
  "domain" => $cookieDomain,
  "secure" => true,
  "httponly" => true,
  "samesite" => "None"
]);

if (isset($_COOKIE['microsoft_access_token'])) {
    setcookie("microsoft_access_token", "", [
        "expires" => time() - 3600,
        "path" => "/",
        "domain" => $cookieDomain,
        "secure" => true,
        "httponly" => true,
        "samesite" => "None"
    ]);
}






echo json_encode(["message" => "Logged out successfully"]);
http_response_code(200);
exit;
