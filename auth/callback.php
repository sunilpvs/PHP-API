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


    require "../vendor/autoload.php";
    require_once($_SERVER['DOCUMENT_ROOT'] . "/classes/authentication/JWTHandler.php");
    require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/DbController.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';


    $env = getenv('APP_ENV') ?: 'local';
    if($env === 'production'){
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
    }else{
        $dotenv= Dotenv::createImmutable(__DIR__ . '/../', '.env');
    }
    $dotenv->load();

    $cookieDomain = $_ENV['COOKIE_DOMAIN'];

    $dbObject = new DbController();
    $config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
    $debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
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

    // Fetch user details from Graph
    $graph = new Graph();
    $graph->setAccessToken($msAccessToken);
    $me = $graph->createRequest("GET", "/me")->setReturnType(Model\User::class)->execute();

     // get the subdomain from state parameter
    $subDomain = $_GET['state'] ?? $_SESSION['portal'] ?? 'vms';

    $email = $me->getMail() ?? $me->getUserPrincipalName();
    $username = $email ?: 'guest';;
    

    // Check if user exists in your DB
    $query = 'SELECT email from tbl_users WHERE email = ?';
    $params = [$email];
    $logger->logQuery($query, $params, 'classes', $module, $username);
    $existingUser = $dbObject->runQuery($query, $params, 'Check existing user by email from Microsoft OAuth');

    
    if(!$existingUser){
        // User does not exist, create new user
    
        // Create User based on OAuth details if not exists
        $query = 'INSERT INTO tbl_contact (f_name, l_name, email, personal_email, city, state, country, emp_status, department, designation, mobile, contacttype_id, entity_id, createdBy) 
                        VALUES (?, ?, ?, ?, 1, 1, 1, 1, 6, 14, ?, ?, 1, 1)';
        $mobilePhone = $me->getMobilePhone() ?? '';
        $params = [$me->getGivenName(), $me->getSurname(), $email, $email, $mobilePhone, 2];
        $logger->logQuery($query, $params, 'classes', $module, $username);
        $userInsertionId = $dbObject->insert($query, $params, 'User contact created from Microsoft OAuth');


        // random password for user creation
        $dummyPassword = bin2hex(random_bytes(8)); // 16 characters
        $query = 'INSERT INTO tbl_users(user_name, email, password, user_status, contact_id, status, entity_id, createdBy)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $hashedPassword = password_hash($dummyPassword, PASSWORD_BCRYPT);
        $params = [$email, $email, $hashedPassword, 1, $userInsertionId, 'verified', 1, 1]; 
        $logger->logQuery($query, $params, 'classes', $module, $username);
        $userId = $dbObject->insert($query, $params, 'User created from Microsoft OAuth with Base Employee role');
        

        $query = 'INSERT INTO tbl_user_modules(user_id, email, module_id, user_role_id, created_by)
                        VALUES(?, ?, ?, ?, ?)';
        $params = [$userId, $email, 2, 5, 1]; 
        $logger->logQuery($query, $params, 'classes', $module, $username);
        $userModuleId = $dbObject->insert($query, $params, 'User module mapping created from Microsoft OAuth with Base Employee role');



        if(!$userId || !$userInsertionId || !$userModuleId)
        {
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

    // Create custom JWT tokens
    $jwt = new JWTHandler();
    $jwtAccess = $jwt->generateAccessToken([

        "sub" => $userId,
        "username" => $email,
        "auth_provider" => "microsoft",
        "domain" => $subDomain,
        "iat"   => time(),
        "exp"   => time() + (60 * 60 * 3)

    ]); // 3 hours


    $jwtRefresh = $jwt->generateRefreshToken([
        
        "sub" => $userId,
        "username" => $email,
        "auth_provider" => "microsoft",
        "domain" => $subDomain,
        "iat" => time(),
        "exp" => time() + (60 * 60 * 3)

    ]); // 3 hours


    // ✅ Set real Microsoft token for Graph requests
    setcookie("microsoft_access_token", $msAccessToken, [
        "expires" => time() + 60 * 60 * 3, // 3 hours
        "path" => "/",
        "secure" => true,
        "domain" => $cookieDomain,
        "httponly" => true,
        "samesite" => "None",
    ]);


    // ✅ Set your custom JWT for API auth
    setcookie("access_token", $jwtAccess, [
        "expires" => time() + 60 * 60 * 3,
        "path" => "/",
        "secure" => true,
        "domain" => $cookieDomain,
        "httponly" => true,
        "samesite" => "None",
    ]);

    setcookie("refresh_token", $jwtRefresh, [
        "expires" => time() + 60 * 60 * 3,
        "path" => "/",
        "secure" => true,
        "domain" => $cookieDomain,
        "httponly" => true,
        "samesite" => "None",
    ]);

   

    $redirectMap = [
        'admin' => $_ENV['ADMIN_PORTAL_URL'],
        'vms' => $_ENV['VMS_PORTAL_URL'],
        'ams' => $_ENV['AMS_PORTAL_URL']
    ];
    
    // Redirect to frontend
    $redirectURI = $redirectMap[$subDomain];
    header("Location: $redirectURI");
    exit;


    //GET https://graph.microsoft.com/v1.0/users/87d349ed-44d7-43e1-9a83-5f2406dee5bd?$select=displayName,givenName,postalCode,identities
    //$strVal = "id,displayName,givenName,postalCode,hireDate,assignedLicenses,accountEnabled,identities";

    // // Commented after Class created - Start
    // $strVal = "id,accountEnabled,employeeId,givenName,surname,displayName,birthday,mail,mobilePhone,businessPhones,faxNumber,preferredLanguage,userPrincipalName,userType,";
    // $strVal .= "companyName,department,jobTitle,hireDate,employeeHireDate,employeeType,employeeOrgData,assignedLicenses,employeeLeaveDateTime,createdDateTime,creationType,identities,";
    // $strVal .= "streetAddress,officeLocation,city,state,postalCode,country,usageLocation";

    // $url = 'https://graph.microsoft.com/v1.0/me?$select='.$strVal;
    // $headers = [
    //      "Authorization: Bearer $accessToken",
    //      "Content-Type: application/json",
    //      "ConsistencyLevel: eventual"
    //    ];

    // //Getting User details
    // $ch = curl_init();
    // curl_setopt($ch, CURLOPT_URL, $url);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // $response = curl_exec($ch);
    // curl_close($ch);
    // $userDetails = json_decode($response, true);

    
    // if (isset($userDetails)) {
    //     $_SESSION['user_details'] = $userDetails;
    //     header('Location: /home');
    //     exit;

    // //Getting User manager details
    // //$urlManager = 'https://graph.microsoft.com/v1.0/users/{id|userPrincipalName}/manager';
    // $urlManager = 'https://graph.microsoft.com/v1.0/me/manager';
    // $ch = curl_init();
    // curl_setopt($ch, CURLOPT_URL, $urlManager);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // $resp = curl_exec($ch);
    // curl_close($ch);
    // $mgnrDetails = json_decode($resp, true);



    // //Teja code
    // $urlPic = 'https://graph.microsoft.com/v1.0/me/photo/$value';

    // $ch = curl_init();
    // curl_setopt($ch, CURLOPT_URL, $urlPic);
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // $respPic = curl_exec($ch);
    // $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close($ch);
    
    // if ($httpCode == 200 && $respPic !== false) {
    //     $imgBase64 = base64_encode($respPic);
    //     echo "<img src='data:image/jpeg;base64,{$imgBase64}' style='border:1px solid black; border-radius:10px; width:100px; height:125px;'>";
    // } else {
    //     echo "Failed to retrieve profile picture.";
    // }
    

    // //end of Teja code    
    // // Commented after Class created - End

    //     echo "Personal Information: ". "<br>";
    //     //echo "Photo: <br> <img src={'data:image/jpeg;base64,' + ". Buffer.from($respPic.data, 'binary').toString('base64'). "}> </img><br>";

    //    // $image = $respPic;    

    //     echo "ID: " . $userDetails['id'] . "<br>";
    //     echo "Account Enabled: " . ($userDetails['accountEnabled'] ? 'Yes' : 'No') . "<br>";
    //     echo "Employee ID: " . $userDetails['employeeId'] . "<br>";
    //     echo "Given Name: " . $userDetails['givenName'] . "<br>";
    //     echo "Surname: " . $userDetails['surname'] . "<br>";
    //     echo "Display Name: " . $userDetails['displayName'] . "<br>";
    //     echo "Birth Date: " . $userDetails['birthday'] . "<br>";
    //     echo "Email ID: " . $userDetails['mail'] . "<br>";
    //     echo "Mobile Phone: " . $userDetails['mobilePhone'] . "<br>";
    //     echo "Business Phones: " . $userDetails['businessPhones'][0] . "<br>";
    //     echo "Fax Number: " . $userDetails['faxNumber'] . "<br>";
    //     echo "Preferred Language: " . $userDetails['preferredLanguage'] . "<br>";
    //     echo "User principal Name: " . $userDetails['userPrincipalName'] . "<br><br>";
    //     //echo "User Type: " . $userDetails['userType'] . "<br><br>";

    //     echo "Address Information: ". "<br><br>";
    //     echo "Street Address: " . $userDetails['streetAddress'] . "<br>";
    //     echo "City: " . $userDetails['city'] . "<br>";
    //     echo "State: " . $userDetails['state'] . "<br>";
    //     echo "Postal Code: " . $userDetails['postalCode'] . "<br>";
    //     echo "Country: " . $userDetails['country'] . "<br>";
    //     echo "Usage Location: " . $userDetails['usageLocation'] . "<br>";

    //     echo "Organization Information: ". "<br>";
    //     echo "Organization Name: " . $userDetails['companyName'] . "<br>";
    //     echo "Office Location: " . $userDetails['officeLocation'] . "<br>";
    //     echo "Reporting Manager: " . $mgnrDetails['displayName'] . "<br>";
    //     echo "Emp Hire Date: " . $userDetails['employeeHireDate'] . "<br>";
    //     //echo "Hire Date: " . $userDetails['hireDate'] . "<br>";
    //     echo "Employee Type: " . $userDetails['employeeType'] . "<br>";
    //     echo "Job Title: " . $userDetails['jobTitle'] . "<br>";
    //     echo "Department: " . $userDetails['department'] . "<br>";
    //     echo "Emp Org Data: " . $userDetails['employeeOrgData'] . "<br>";
    //     //echo "Assigned Licenses: " . $userDetails['assignedLicenses'] . "<br>";
    //     echo "License: " . $userDetails['assignedLicenses'][0]['skuId'] . "<br>";
    //     echo "Employee Release Date: " . $userDetails['employeeLeaveDateTime'] . "<br>";
    //     echo "Created Date: " . $userDetails['createdDateTime'] . "<br>";
    //     echo "Creation Type: " . $userDetails['creationType'] . "<br><br>";
    //     //echo "Identities: " . $userDetails['identities'] . "<br><br>";        
        
    // } else {
    //     echo "Failed to retrieve user details.";
    // }
   
    // $user = new User;
    
    // echo 'Id: ' . $user->data->getId() . '<br>';
    // echo 'Given Name: ' . $user->data->getGivenName() . '<br>';
    // echo 'Sur Name: ' . $user->data->getSurName() . '<br>';
    // echo 'Display Name: ' . $user->data->getDisplayName() . '<br><br>';

    // echo 'Job Title: ' . $user->data->getJobTitle() . '<br>';
    // echo "Email: " . $user->data->getUserPrincipalName() . "<br>";
    // echo 'Mobile Phone: ' . $user->data->getMobilePhone() . '<br><br>';

    // $tmp = $user->data->getCompanyName();
    // echo 'Company Name: ' . $user->data->getCompanyName() . '<br>';

    // echo 'Office Location: ' . $user->data->getOfficeLocation() . '<br>';
    // echo 'Street Address: ' . $user->data->getStreetAddress() . '<br>';
    // echo 'City: ' . $user->data->getCity() . '<br>';
    // echo 'State: ' . $user->data->getState() . '<br>';
    // echo 'Country: ' . $user->data->getCountry() . '<br>';
    // echo 'Postal Code: ' . $user->data->getPostalCode() . '<br>';
    // echo 'Usage Location: ' . $user->data->getUsageLocation() . '<br><br>';
    
    // //getPhoneNumber()businessPhones
    // //echo 'Business Phones: ' . $user->data->getBusinessPhones() . '<br>';   
    // echo 'EmployeeId: ' . $user->data->getEmployeeId() . '<br>';
    // //echo 'Employee Hire Date: ' . $user->data->getEmployeeHireDate() . '<br>';
    // echo 'Employee Hire Date: ' . $user->data->getHireDate() . '<br>';
    // echo 'Employee Type: ' . $user->data->getEmployeeType() . '<br>';
    // echo 'Department: ' . $user->data->getDepartment() . '<br>';  
    // echo 'Reporting Manager: ' . $user->data->getManager() . '<br>';
    // echo 'Member Of: ' . $user->data->getMemberOf() . '<br>';
    // echo 'CreatedDateTime: ' . $user->data->getCreatedDateTime() . '<br><br>';

    // echo 'Assigned Licenses: ' . $user->data->getAssignedLicenses() . '<br>';
    // $isEnabled = $user->data->getAccountEnabled();
    // if($isEnabled)
    // {
    //     echo "Account Enabled = TRUE";
    // }
    // echo 'Account Enabled: ' . $user->data->getAccountEnabled() . '<br>';
    // echo 'Account Enabled: ' . $isEnabled . '<br>';
    // echo 'Authorization Info: ' . $user->data->getAuthorizationInfo() . '<br>';
    // echo 'License Details: ' . $user->data->getLicenseDetails() . '<br>';
?>