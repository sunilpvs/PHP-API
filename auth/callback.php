<?php
// header("Access-Control-Allow-Origin: http://localhost:5173");
// header("Access-Control-Allow-Credentials: true");
// header("Access-Control-Allow-Headers: Content-Type, Authorization");
// header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
use myPHPnotes\Microsoft\Auth;
use myPHPnotes\Microsoft\Handlers\Session;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;

use Dotenv\Dotenv;

session_start();

require "../vendor/autoload.php";
require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/authentication/JWTHandler.php");
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


$env = getenv('APP_ENV') ?: 'local';
if ($env === 'production') {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
} else {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env');
}
$dotenv->load();

$cookieDomain = $_ENV['COOKIE_DOMAIN'];

$dbObject = new DbController();
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$module = 'OAuth';

// Load config
$ini_file_path = $_SERVER['DOCUMENT_ROOT'] . "/app.ini";
$config = parse_ini_file($ini_file_path);

// $env = getenv('APP_ENV') ?: 'local';
// if($env === 'production'){
//     $tenant_id     = $config['prod_tenant_id'];
//     $client_id     = $config['prod_clientId'];
//     $client_secret = $config['prod_clientSecret'];
//     $redirect_uri  = $config['prod_redirectUri'];
//     $scopes        = explode(" ", $config['prod_scopes']);

// }else{
//     $dotenv= Dotenv::createImmutable(__DIR__ . '/../', '.env');
// }

$tenant_id     = $config['tenant_id'];
$client_id     = $config['clientId'];
$client_secret = $config['clientSecret'];
$redirect_uri  = $_ENV['MICROSOFT_REDIRECT_URI'];
$scopes        = explode(" ", $config['scopes']);

// Microsoft OAuth
$auth = new Auth($tenant_id, $client_id, $client_secret, $redirect_uri, $scopes);
$tokens = $auth->getToken($_REQUEST['code'], Session::get("state"));
$msAccessToken = $tokens->access_token; // ✅ Real Microsoft token
$auth->setAccessToken($msAccessToken);

// $idToken = $tokens->id_token ?? null;

// var_dump($idToken); // Debug: Check if ID token is present

// $idToken = $tokens->id_token;

// $payload = json_decode(base64UrlDecode(explode('.', $idToken)[1]), true);

// $roles = $payload['roles'] ?? [];

// 🔐 Decode token to extract tenant ID
function base64UrlDecode($data)
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

$tokenParts = explode('.', $msAccessToken);

if (count($tokenParts) !== 3) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JWT structure"]);
    exit();
}

$payload = json_decode(base64UrlDecode($tokenParts[1]), true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(["error" => "Failed to decode token"]);
    exit();
}

$tenantId = $payload['tid'] ?? null;




// ✅ Load allowed tenants from config
$allowedTenants = array_map('trim', explode(',', $config['allowed_tenants'] ?? ''));

// 🚫 Reject if tenant not allowed
if (!$tenantId || !in_array($tenantId, $allowedTenants)) {
    http_response_code(403);
    echo json_encode([
        "error" => "Unauthorized tenant",
        "tenant" => $tenantId
    ]);
    exit();
}

// Fetch user details from Graph
$graph = new Graph();
$graph->setAccessToken($msAccessToken);
$me = $graph->createRequest("GET", "/me")->setReturnType(Model\User::class)->execute();

// get the subdomain from state parameter
$subDomain = $_REQUEST['state'] ?? $_SESSION['portal'];

$email = $me->getMail() ?? $me->getUserPrincipalName();
$username = $email ?: 'guest';;


// Check if user exists in your DB
$query = 'SELECT email from tbl_users WHERE email = ?';
$params = [$email];
$logger->logQuery($query, $params, 'classes', $module);
$existingUser = $dbObject->runQuery($query, $params);


if (!$existingUser) {
    // User does not exist, create new user

    // Get manager details
    // handle case where user might not have a manager assigned in Azure AD
    $manager = null;

    try {

        $manager = $graph->createRequest("GET", "/me/manager")
            ->setReturnType(Model\User::class)
            ->execute();
    } catch (\Throwable $e) {

        $logger->log(
            "Manager lookup failed for $email : " . $e->getMessage(),
            'classes',
            $module
        );

        $manager = null;
    }

    $managerName = $manager ? $manager->getDisplayName() : 'No Manager';
    $managerEmail = $manager
        ? ($manager->getMail() ?? $manager->getUserPrincipalName())
        : 'No Manager Email';

    // Create User based on OAuth details if not exists
    $query = 'INSERT INTO tbl_contact (f_name, l_name, email, personal_email, city, state, country, emp_status, department, designation, mobile, contacttype_id, entity_id, createdBy) 
                        VALUES (?, ?, ?, ?, 1, 1, 1, 1, 6, 14, ?, ?, 1, 1)';
    $mobilePhone = $me->getMobilePhone() ?? '';
    $lname = $me->getSurname() ?? ' ';
    $params = [$me->getGivenName(), $lname, $email, $email, $mobilePhone, 2];
    $logger->logQuery($query, $params, 'classes', $module);
    $userInsertionId = $dbObject->insert($query, $params, 'User contact created from Microsoft OAuth');


    // random password for user creation
    $dummyPassword = bin2hex(random_bytes(8)); // 16 characters
    $query = 'INSERT INTO tbl_users(user_name, email, password, user_status, contact_id, status, entity_id, createdBy, manager, manager_email)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $hashedPassword = password_hash($dummyPassword, PASSWORD_BCRYPT);
    $params = [$email, $email, $hashedPassword, 1, $userInsertionId, 'verified', 1, 1, $managerName, $managerEmail];
    $logger->logQuery($query, $params, 'classes', $module);
    $userId = $dbObject->insert($query, $params, 'User created from Microsoft OAuth with Base Employee role');


    $query = 'INSERT INTO tbl_user_modules(user_id, email, module_id, user_role_id, created_by)
                        VALUES(?, ?, ?, ?, ?)';
    $params = [$userId, $email, 2, 5, 1];
    $logger->logQuery($query, $params, 'classes', $module);
    $userModuleId = $dbObject->insert($query, $params, 'User module mapping created from Microsoft OAuth with Base Employee role');



    if (!$userId || !$userInsertionId || !$userModuleId) {
        // Handle error
        http_response_code(500);
        echo json_encode(["error" => "Failed to create user"]);
        exit();
    }
}

// get user ID for JWT
$query = 'SELECT id FROM tbl_users WHERE email = ?';
$params = [$email];
$logger->logQuery($query, $params, 'classes', $module, $username);
$result = $dbObject->runSingle($query, $params, 'Fetch user ID for JWT from Microsoft OAuth');
$userId = $result['id'];

// get allowed domains for this user
$query = "SELECT lower(m.module_name) as module FROM tbl_user_modules um 
                JOIN tbl_module m 
                ON um.module_id = m.module_id 
                WHERE um.email=?";
$params = [$email];
$logger->logQuery($query, $params, 'classes', $module, $username);
$allowedDomains = $dbObject->runQuery($query, $params, 'Fetch allowed domains for user from Microsoft OAuth');

$allowedDomains = array_column($allowedDomains, 'module'); // Extract module names into a simple array


// get expiry time from Microsoft token response and normalize to a valid future Unix epoch
$now = time();
$msTokenExpiryEpoch = null;

if (isset($tokens->expires_on)) {
    $expiresOn = $tokens->expires_on;

    if (is_numeric($expiresOn)) {
        $parsedExpiry = (int) $expiresOn;
        $msTokenExpiryEpoch = $parsedExpiry;
    } else {
        $parsedExpiry = strtotime((string) $expiresOn);
        if ($parsedExpiry !== false) {
            $msTokenExpiryEpoch = $parsedExpiry;
        }
    }
}

if ($msTokenExpiryEpoch === null && isset($tokens->expires_in) && is_numeric($tokens->expires_in)) {
    $msTokenExpiryEpoch = $now + (int) $tokens->expires_in;
}

$sessionExpiryEpoch = $msTokenExpiryEpoch;


// Create custom JWT tokens
$jwt = new JWTHandler();
$jwtAccess = $jwt->generateAccessToken([

    "sub" => $userId,
    "username" => $email,
    "auth_provider" => "microsoft",
    "allowed_domains" => $allowedDomains,
    "iat"   => $now,
    "exp"   => $sessionExpiryEpoch

]);


$jwtRefresh = $jwt->generateRefreshToken([

    "sub" => $userId,
    "username" => $email,
    "auth_provider" => "microsoft",
    "allowed_domains" => $allowedDomains,
    "iat" => $now,
    "exp" => $sessionExpiryEpoch

]);



// ✅ Set real Microsoft token for Graph requests
setcookie("microsoft_access_token", $msAccessToken, [
    "expires" => $sessionExpiryEpoch,
    "path" => "/",
    "secure" => true,
    "domain" => $cookieDomain,
    "httponly" => true,
    "samesite" => "None",
]);


// ✅ Set your custom JWT for API auth
setcookie("access_token", $jwtAccess, [
    "expires" => $sessionExpiryEpoch,
    "path" => "/",
    "secure" => true,
    "domain" => $cookieDomain,
    "httponly" => true,
    "samesite" => "None",
]);

setcookie("refresh_token", $jwtRefresh, [
    "expires" => $sessionExpiryEpoch,
    "path" => "/",
    "secure" => true,
    "domain" => $cookieDomain,
    "httponly" => true,
    "samesite" => "None",
]);

$redirectPortal = $_GET['portal'] ?? $_SESSION['portal'] ?? 'default';

$redirectMap = [
    'default' => $_ENV['INTERNAL_PORTAL_URL'],
    'admin' => $_ENV['ADMIN_PORTAL_URL'],
    'vms' => $_ENV['VMS_PORTAL_URL'],
    'ams' => $_ENV['AMS_PORTAL_URL']
];

// Redirect to frontend
$redirectURI = $redirectMap[$redirectPortal] ?? $redirectMap['default'];
header("Location: $redirectURI");
exit;
