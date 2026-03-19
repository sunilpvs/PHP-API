<?php

// SharePoint Holiday Calendar API
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

$user = new UserLogin();
$token = $user->getToken();

// get siteId and listId from app.ini
$app = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . "/app.ini");
$siteId = $app['siteId'];
$listId = $app['listId'];

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
    $graph = new \Microsoft\Graph\Graph();
    $graph->setAccessToken($accessToken);



    if (!$accessToken) {
        http_response_code(401);
        echo json_encode(["error" => "Access token missing"]);
        exit();
    }

    $graph = new \Microsoft\Graph\Graph();
    $graph->setAccessToken($accessToken);

    try{
        $me = $graph->createRequest("GET", "/sites/$siteId/lists/$listId/items?\$expand=fields")
                    ->setReturnType(\Microsoft\Graph\Model\ListItem::class)
                    ->execute();

        $items = [];
        foreach ($me as $item) {
            $fields = $item->getFields();
            $fieldValues = $fields ? $fields->getProperties() : [];
            $items[] = [
                'id' => $item->getId(),
                'title' => $fieldValues['Title'] ?? null,
                'date' => $fieldValues['HolidayDate'] ?? null,
                'branches' => $fieldValues['Branches'] ?? null,
                'description' => $fieldValues['Description'] ?? null,
            ];
        }

        echo json_encode([
            "message" => "Holiday calendar fetched successfully",
            "data" => $items
        ]);
        exit();
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to fetch holiday calendar", "details" => $e->getMessage()]);
        exit();
    }

}

