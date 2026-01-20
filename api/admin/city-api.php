<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}
require_once __DIR__ . '../../../classes/admin/City.php';
require_once __DIR__. '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

//Validate login and authenticate JWT
    authenticateJWT();

//Reading app.ini configuration file
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$regExp = '/^[a-zA-Z\s]+$/';
//Front End authorization as Trusted Hosts.

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$cityOb = new City();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $cityOb->getCityById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "City not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'city'];
            $fields = array_map('trim', $fields); 
            $data = $cityOb->getCityCombo($fields, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        $data = $cityOb->getPaginatedCities($offset, $limit, $module, $username);
        $total = $cityOb->getCitiesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'cities' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($input['city']) || empty(trim($input['city']))) {
            http_response_code(400);
            $error = ["error" => "City name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['state']) || empty(trim($input['state']))) {
            http_response_code(400);
            $error = ["error" => "State name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['country']) || empty(trim($input['country']))) {
            http_response_code(400);
            $error = ["error" => "Country name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $city = trim($input['city']);
        $state = trim($input['state']);
        $country = trim($input['country']);

        if (!preg_match($regExp, $city)) {
            http_response_code(400);
            $error = ["error" => "City name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }
        // if (!preg_match($regExp, $state)) {
        //     http_response_code(400);
        //     $error = ["error" => "State name must contain only alphabets and spaces"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse($input, $error);
        //     break;
        // }
        // if (!preg_match($regExp, $country)) {
        //     http_response_code(400);
        //     $error = ["error" => "Country name must contain only alphabets and spaces"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse($input, $error);
        //     break;
        // }
        // var_dump($city, $state, $countr)

        $duplicate = $cityOb->checkDuplicateCity($city, $country, $state);
        if ($duplicate) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: City already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }
        
        $logger->logRequestAndResponse(["incomingName" => $city], []);
        
        $result = $cityOb->insertCity($city, $state, $country, $module, $username);
        $logger->log("Insert result: " . print_r($result, true));

        if ($result) {
            http_response_code(201);
            $response = ["message" => "City added successfully", "id" => (int)$result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add city"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");
        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "City ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "City ID must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        if (!isset($input['city']) || empty(trim($input['city']))) {
            http_response_code(400);
            $error = ["error" => "City name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['state']) || empty(trim($input['state']))) {
            http_response_code(400);
            $error = ["error" => "State name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['country']) || empty(trim($input['country']))) {
            http_response_code(400);
            $error = ["error" => "Country name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $id = intval($_GET['id']);
        $city = trim($input['city']);
        $state = trim($input['state']);
        $country = trim($input['country']);



        if (!preg_match('/^[a-zA-Z\s]+$/', $city)) {
            http_response_code(400);
            $error = ["error" => "City name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }
        // if (!preg_match('/^[a-zA-Z\s]+$/', $state)) {
        //     http_response_code(400);
        //     $error = ["error" => "State name must contain only alphabets and spaces"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        //     break;
        // }
        // if (!preg_match('/^[a-zA-Z\s]+$/', $country)) {
        //     http_response_code(400);
        //     $error = ["error" => "Country name must contain only alphabets and spaces"];
        //     echo json_encode($error);
        //     $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        //     break;
        // }
        
        $duplicate = $cityOb->checkEditDuplicateCity($city, $state, $country, $id);
        if ($duplicate) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: City already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }
        

        $result = $cityOb->updateCity($city, $state, $country,$id, $module, $username);
        if ($result !== false) {
            http_response_code(200);
            $response = ["message" => $result > 0 ? "City updated successfully" : "No changes made"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to update City"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");
        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "City ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }
        $id = intval($_GET['id']);
        $result = $cityOb->deleteCity($id, $module, $username);
        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "City deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete city"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
?>