<?php

// CORS headers

// header("Access-Control-Allow-Origin: http://localhost:5173");
// header("Access-Control-Allow-Credentials: true");
// header("Access-Control-Allow-Headers: Content-Type, Authorization");
// header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT']."/classes/authentication/JWTHandler.php");


function authenticateJWT() {
    $token = $_COOKIE['access_token'] ?? '';
    $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
    $portals = array_map('trim', explode(',', $config['portals']['portals']));
    
    if (!$token) {
        $headers = getallheaders();
        if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }


    if (!$token) {
        http_response_code(401);
        echo json_encode(["error" => "Access token not found"]);
        exit;
    }
    
    $jwt = new JWTHandler();
    $verified = $jwt->verifyToken($token);
    $allowed_domains = $verified->allowed_domains ?? null;

    if($verified === false){
        http_response_code(401);
        echo json_encode(["error" => "Invalid or expired token"]);
        exit;
    }

    if (!$allowed_domains) {
        http_response_code(401);
        echo json_encode(["error" => "Allowed domains not found in token"]);
        exit();
    }
    

    if($allowed_domains && empty(array_intersect($allowed_domains, $portals))) {
        http_response_code(401);
        echo json_encode(["error" => "Invalid portal in token"]);
        exit();
    }

    return $verified;   



    
}