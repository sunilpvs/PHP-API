<?php

    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit();
    }

require_once($_SERVER['DOCUMENT_ROOT']."/vendor/autoload.php");
require_once($_SERVER['DOCUMENT_ROOT']."/classes/authentication/JWTHandler.php");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}


function verifyToken($middleware_portal) {

    $jwt = new JWTHandler();

    $token = $_COOKIE['access_token'] ?? '';


    if (!$token) {
        $headers = getallheaders();
        if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }

    $verified = $jwt->verifyToken($token);
    $user = $verified->username ?? null;
    $portal = $verified->domain ?? null;

    if (!$user) {
        http_response_code(401);
        echo json_encode(["error" => "User not found"]);
        exit();
    }

    if (!$portal) {
        http_response_code(401);
        echo json_encode(["error" => "Portal not found"]);
        exit();
    }

    if (!$verified) {
        http_response_code(401);
        echo json_encode(["error" => "Invalid or expired token"]);
        exit();
    }

    if($portal !== 'admin' && $portal !== 'vms' && $portal !== 'vendor'){
        http_response_code(401);
        echo json_encode(["error" => "Invalid portal in token"]);
        exit();
    }

    if($portal !== $middleware_portal){
        http_response_code(401);
        echo json_encode(["error" => "Portal mismatch"]);
        exit();
    }

    http_response_code(200);
    echo json_encode(["message" => "Access granted"]);
    exit();
    
}



?>