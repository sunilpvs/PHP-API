<?php 

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
    $graph = new \Microsoft\Graph\Graph();
    $graph->setAccessToken($accessToken);



    if (!$accessToken) {
        http_response_code(401);
        echo json_encode(["error" => "Access token missing"]);
        exit();
    }

    $graph = new \Microsoft\Graph\Graph();
    $graph->setAccessToken($accessToken);

    try {
        $me = $graph->createRequest("GET", "/me?\$select=displayName,jobTitle,department,city,state,country,mail,userPrincipalName,mobilePhone,givenName,surname")
                    ->setReturnType(\Microsoft\Graph\Model\User::class)
                    ->execute();

            function getMSUserProfilePicture($accessToken)
            {
                
                $urlPic = 'https://graph.microsoft.com/v1.0/me/photo/$value';
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $urlPic);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                                "Authorization: Bearer ".$accessToken,
                                                "Content-Type: application/json",
                                                "ConsistencyLevel: eventual"
                                            ]);
                $respPic = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode == 200 && $respPic !== false)
                {
                    $imgBase64 = base64_encode($respPic);
                    return $imgBase64;
                }
                else
                {
                    //echo "Failed to retrieve profile picture.";
                    return false;
                }    
            }
      

        $userData = [
            'user_name'      => $me->getUserPrincipalName(),
            'f_name'         => $me->getGivenName(), // Graph doesn't provide this directly
            'l_name'         => $me->getSurname(), // Graph doesn't provide this directly
            'mobile'         => $me->getMobilePhone(), // Optional: Requires /me/mobilePhone scope
            'dob'            => '', 
            'address1'       => '',
            'address2'       => '',
            'city'           => $me->getCity(),
            'state'          => $me->getState(),
            'country'        => $me->getCountry(),
            'email'          => $me->getMail() ?? $me->getUserPrincipalName(),
            'personal_email' => $me->getMail() ?? $me->getUserPrincipalName(),
            'status'         => 'Active', 
            'user_role'      => $me->getJobTitle(), 
            'joining_date'   => '',
            'exit_date'      => '',
            'department'     => $me->getDepartment() ?? '',
            'designation'    => $me->getJobTitle() ?? '',
            'profile_pic'    => getMSUserProfilePicture($accessToken) ?: '', // Get profile picture

        ];

        echo json_encode([
            "message" => "User details retrieved successfully",
            "userData" => $userData
        ]);
        exit();

    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Microsoft Graph request failed", "details" => $e->getMessage()]);
        exit();
    }
} else {
    // Local user
    $user = new UserLogin();
    $result = $user->getAllUserDetails($username);

    if (!$result) {
        http_response_code(401);
        echo json_encode(["error" => "User not found"]);
        exit();
    }

    $userData = $user->getUserData($result);

    echo json_encode([
        "message" => "User details retrieved successfully",
        "userData" => $userData
    ]);
}

?>