<?php
    // Set timezone to UTC for consistent JWT expiry handling
    date_default_timezone_set('UTC');
    
    require __DIR__.'../../vendor/autoload.php';
    use League\OAuth2\Client\Provider\GenericProvider;
    use Dotenv\Dotenv;

    $ini_file_path = $_SERVER['DOCUMENT_ROOT'] ."/app.ini";
    $config = parse_ini_file($ini_file_path);
    $app_url = $config["app_url"];


    $env = getenv('APP_ENV') ?: 'local';
    if($env === 'production'){
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env.prod');
    }else{
        $dotenv= Dotenv::createImmutable(__DIR__ . '/../', '.env');
    }
    $dotenv->load();


    $provider = new GenericProvider([
        'tenant_id'               => $config['tenant_id'],
        'clientId'                => $config['clientId'],
        'clientSecret'            => $config['clientSecret'],
        'redirectUri'             => $_ENV['MICROSOFT_REDIRECT_URI'],
        'urlAuthorize'            => $config['urlAuthorize'],
        'urlAccessToken'          => $config['urlAccessToken'],
        'urlResourceOwnerDetails' => $config['urlResourceOwnerDetails'],
        'scopes'                  => $config['scopes'],
    ]);

    // Detect subdomain (portal) from url parameter or session
    $portal = $_GET['portal'] ?? 'internal'; // Default to 'internal' if no portal specified


    $_SESSION['portal'] = $portal;
    // echo "Portal: " . $portal . "\n";

    if (!isset($_GET['code'])) {
        // Step 1. Get authorization code
        $authorizationUrl = $provider->getAuthorizationUrl([
            'state' => $portal, // Pass portal as state parameter
        ]);
        $_SESSION['oauth2state'] = $provider->getState();
        header('Location: ' . $authorizationUrl);
        exit;
    } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        // State is invalid, possible CSRF attack in progress
        unset($_SESSION['oauth2state']);
        exit('Invalid state');
    } else {
        // Step 2. Get an access token using the authorization code
        try {
            $accessToken = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']
            ]);
            
            // Step 3. Get the user's profile
            $resourceOwner = $provider->getResourceOwner($accessToken);
            $redirectPortal = $_SESSION['portal'] ?? 'internal';
            header("Location: ./callback.php?portal=$redirectPortal");
            exit;
        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            exit('Failed to get access token: ' . $e->getMessage());
        }
    }

?>