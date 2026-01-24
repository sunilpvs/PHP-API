<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/Country.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';

authenticateJWT(); // Enable JWT authentication

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$countryObj = new Country();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT();
// $username = 'guest';
$module = 'Admin';

$regExp = '/^[a-zA-Z\s]+$/';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $countryObj->getCountryById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Country not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'country'];
            $fields = array_map('trim', $fields);
            $data = $countryObj->getCountryCombo($fields, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $countryObj->getPaginatedCountries($offset, $limit, $module, $username);
        $total = $countryObj->getCountriesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'countries' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (
            !isset($input['country'], $input['currency'], $input['code']) ||
            empty(trim($input['country'])) ||
            empty(trim($input['currency'])) ||
            empty(trim($input['code']))
        ) {
            http_response_code(400);
            $error = ["error" => "Country, Currency, and Code are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $country = trim($input['country']);
        $currency = trim($input['currency']);
        $code = trim($input['code']);

        if (!preg_match($regExp, $country)) {
            http_response_code(400);
            $error = ["error" => "Country must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match('/^[A-Z]{2,4}$/', $currency)) {
            http_response_code(400);
            $error = ["error" => "Currency must be 2-4 uppercase letters only and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match('/^[A-Z]{2,3}$/', $code)) {
            http_response_code(400);
            $error = ["error" => "Country code must be 2–3 uppercase letters (e.g. IN, USA)"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if ($countryObj->checkDuplicateCountry($country, $currency, $code)) {
            http_response_code(400);
            $error = ["error" => "Duplicate country record"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $countryObj->insertCountry($country, $currency, $code, $module, $username);

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Country added successfully", "id" => (int)$result];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to add country"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (
            !isset($_GET['id']) ||
            !isset($input['country'], $input['currency'], $input['code']) ||
            empty(trim($input['country'])) ||
            empty(trim($input['currency'])) ||
            empty(trim($input['code']))
        ) {
            http_response_code(400);
            $error = ["error" => "ID, Country, Currency, and Code are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $id = intval($_GET['id']);
        $country = trim($input['country']);
        $currency = trim($input['currency']);
        $code = trim($input['code']);

        if (!preg_match($regExp, $country)) {
            http_response_code(400);
            $error = ["error" => "Country must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($regExp, $currency)) {
            http_response_code(400);
            $error = ["error" => "Currency must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match('/^[A-Z]{2,3}$/', $code)) {
            http_response_code(400);
            $error = ["error" => "Country code must be 2–3 uppercase letters"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if ($countryObj->checkEditDuplicateCountry($country, $currency, $code, $id)) {
            http_response_code(400);
            $error = ["error" => "Duplicate country record"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $countryObj->updateCountry($country, $currency, $code, $id, $module, $username);

        if ($result !== false) {
            http_response_code(200);
            $response = ["message" => $result > 0 ? "Country updated successfully" : "No changes made"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to update country"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Country ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $countryObj->deleteCountry($id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Country deleted successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to delete country"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
