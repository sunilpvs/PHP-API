<?php
require_once(__DIR__ . '/../classes/authentication/middle.php');
require_once(__DIR__ . '/../classes/authentication/LoginUser.php');
 
// ✅ authenticate user by JWT
$userId = authenticateJWT();
 
$data = json_decode(file_get_contents("php://input"), true);
$newPassword = $data['new_password'] ?? null;
 
if (!$newPassword) {
    http_response_code(400);
    echo json_encode(["error" => "New password required"]);
    exit;
}
 
// hash password
$hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
 
// update DB
$user = new UserLogin();
$user->resetPassword($userId, $hashedPassword);
if(!$user){
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Failed to reset password"
    ]);
    exit;
}else if($user){
    // Password reset successful
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Password reset successful"
    ]);
}
 
