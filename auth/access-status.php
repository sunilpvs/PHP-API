<?php

// auth/access-status.php

require_once(__DIR__ . "/../classes/admin/AccessRequest.php");
require_once(__DIR__ . "/../classes/authentication/JWTHandler.php");
require_once(__DIR__ . "/../classes/authentication/LoginUser.php");

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


$user = new UserLogin();
$accessReq = new AccessRequest();
$token = $user->getToken();

if(!$token){
    http_response_code(401);
    echo json_encode(["error" => "Access token not found"]);
    exit();
}

$jwt = new JWTHandler();

try {
    $decodedToken = $jwt->decodeJWT($token);
} catch (\Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token"]);
    exit();
}

$email = $decodedToken['username'] ?? '';

if (!isset($_GET['type'])) {
    http_response_code(400);
    $error = ["error" => "Missing type parameter"];
    echo json_encode($error);
    exit();
}

$type = $_GET['type'];

if($type !== 'vms' && $type !== 'admin'){
    http_response_code(400);
    echo json_encode(["error" => "Invalid type. Use vms for vms access check or admin for admin access check"]);
    exit();
}


if($type == 'vms'){
    $vmsAccess = $accessReq->checkVmsAccess($email, 'Access Request', $email);
    $reqStatus = $accessReq->getVmsAccessStatus($email, 'Access Request', $email);
    if($vmsAccess>0){
        http_response_code(200);
        echo json_encode([
            "vms_access" => "granted",
        ]);
        exit();
    } else {
        http_response_code(403);
        echo json_encode([
            "error" => "Access denied",
            "req_status" => $reqStatus ?? 'no_request'
        ]);
        exit();
    }
}

if($type == 'admin'){
    $adminAccess = $accessReq->checkAdminAccess($email, 'Access Request', $email);
    $reqStatus = $accessReq->getAdminAccessStatus($email, 'Access Request', $email);
    if($adminAccess>0){
        http_response_code(200);
        echo json_encode([
            "admin_access" => "granted",
        ]);
        exit();
    } else {
        http_response_code(403);
        echo json_encode([
            "error" => "Access denied",
            "req_status" => $reqStatus ?? 'no_request'
        ]);
        exit();
    }
}

if($type == 'vendor'){
    $vendorAccess = $accessReq->checkVendorStatus($email, 'Access Request', $email);
    if($vendorAccess>0){
        http_response_code(200);
        echo json_encode([
            "status" => "approved",
        ]);
        exit();
    } else {
        http_response_code(403);
        echo json_encode([
            "status" => "rejected",
        ]);
        exit();
    }
}






?>



