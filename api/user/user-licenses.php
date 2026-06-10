<?php

// This endpoint is used to get the licenses of the user. 
// send a request to /me/licenseDetails endpoint of Microsoft Graph API to get the licenses of the user.

require_once(__DIR__ . "/../../classes/authentication/JWTHandler.php");
require_once(__DIR__ . "/../../classes/authentication/LoginUser.php");

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$user = new UserLogin();
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

$authProvider = $decodedToken['auth_provider'] ?? 'local';
$username = $decodedToken['username'] ?? '';

if ($authProvider === 'microsoft') {
    require_once($_SERVER['DOCUMENT_ROOT'] . "/vendor/autoload.php");

    $accessToken = $_COOKIE['microsoft_access_token'] ?? null;

    if (!$accessToken) {
        http_response_code(401);
        echo json_encode(["error" => "Access token missing"]);
        exit();
    }

    $graph = new \Microsoft\Graph\Graph();
    $graph->setAccessToken($accessToken);

    try {
        $licenses = $graph->createRequest("GET", "/me/licenseDetails")
                    ->setReturnType(\Microsoft\Graph\Model\LicenseDetails::class)
                    ->execute();

        $licenseData = [];
        foreach ($licenses as $license) {
            $licenseData[] = [
                "id" => $license->getId(),
                "skuId" => $license->getSkuId(),
                "skuPartNumber" => $license->getSkuPartNumber()
            ];
        }

        echo json_encode(["licenses" => $licenseData]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to retrieve licenses"]);
    }
} else {
    http_response_code(400);
    echo json_encode(["error" => "Unsupported authentication provider"]);
}