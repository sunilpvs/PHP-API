<?php

// header("Access-Control-Allow-Origin: http://localhost:5173");
// header("Access-Control-Allow-Credentials: true");
// header("Access-Control-Allow-Headers: Content-Type, Authorization");
// header("Access-Control-Allow-Methods: GET, POST, OPTIONS");




// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . "/vendor/autoload.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/authentication/JWTHandler.php");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once('../classes/authentication/VerifyToken.php');

$portal = $_GET['portal'];

if (!isset($_GET['portal'])) {
    http_response_code(400);
    echo json_encode(["error" => "Portal parameter is required"]);
    exit();
}

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$portals = array_map('trim', explode(',', $config['portals']['portals']));

if (!in_array($portal, $portals)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid portal specified"]);
    exit();
}
$user = verifyToken($portal);


error_log("Received cookies: " . json_encode($_COOKIE));

echo json_encode([
    "message" => "Authenticated",
    "user" => $user
]);
