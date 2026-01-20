<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/vms/CounterPartyInfo.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';

// Validate login and authenticate JWT
authenticateJWT();

// Configuration & logger
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$vendorOb = new CounterPartyInfo();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'VMS';

switch ($method) {

    case 'GET':
        $logger->log("GET request received", 'api', $module, $username);

        if (isset($_GET['reference_id'])) {
            $reference_id = $_GET['reference_id'];
            if(empty($reference_id)){
                http_response_code(400);
                $error = ["error" => "Reference ID cannot be empty"];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error, 'api', $module, $username);
                exit;
            }
            $data = $vendorOb->getCounterpartyByReferenceId($reference_id, $module, $username);
            $response = ['counterparty' => $data];
            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response, 'api', $module, $username);
            break;
        }
        // If no reference_id, return all counterparties via pagination
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 10;
        $offset = ($page - 1) * $limit;
        $data = $vendorOb->getPaginatedCounterpartyDetails($offset, $limit, $module, $username);
        $response = ['counterparties' => $data];
        http_response_code(200);
        echo json_encode($response);

    case 'POST':
        $logger->log("POST request received", 'api', $module, $username);

        // Get reference_id from URL parameter
        $reference_id = $_GET['reference_id'] ?? null;

        if (empty($reference_id)) {
            http_response_code(400);
            $error = ["error" => "Reference ID is required in URL parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error, 'api', $module, $username);
            exit;
        }

        // Required fields for POST request
        $requiredFields = ['full_registered_name', 'business_entity_type', 'registered_address'];

        // Validate required fields
        foreach ($requiredFields as $field) {
            if (!isset($input[$field]) || empty(trim($input[$field]))) {
                http_response_code(400);
                $error = ["error" => "Required field '$field' is missing or empty"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error, 'api', $module, $username);
                exit;
            }
        }

        // Validate business entity type
        $validEntityTypes = [
            'Sole Proprietorship',
            'Partnership',
            'Limited Liability Partnership',
            'Public Limited Companies',
            'Private Limited Companies',
            'One-Person Companies',
            'Section 8 Company',
            'Joint-Venture Company',
            'Non-Government Organization(NGO)'
        ];

        if (!in_array($input['business_entity_type'], $validEntityTypes)) {
            http_response_code(400);
            $error = ["error" => "Invalid business entity type"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error, 'api', $module, $username);
            exit;
        }


        try {

            if($input['country_type'] == 'India'){
                // Make country_text and state_text null for India
                $input['country_text'] = null;
                $input['state_text'] = null;
                // Validate country_id and state_id for India
                if (empty($input['country_id']) || empty($input['state_id'])) {
                    http_response_code(400);
                    $error = ["error" => "country_id and state_id are required for country_type 'India'"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error, 'api', $module, $username);
                    exit;
                }
            } else {
                // For other countries, country_id and state_id should be null
                $input['country_id'] = null;
                $input['state_id'] = null;
                // Validate country_text for other countries
                if (empty($input['country_text']) || empty($input['state_text'])) {
                    http_response_code(400);
                    $error = ["error" => "country_text and state_text are required for country_type other than 'India'"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error, 'api', $module, $username);
                    exit;
                }
            }

            
            // Insert vendor
            $result = $vendorOb->insertCounterparty(
                $reference_id,
               strtoupper($input['full_registered_name'] ?? null),
                strtoupper($input['business_entity_type'] ?? null),
                strtoupper($input['reg_number'] ?? null),
                strtoupper($input['tan_status'] ?? null),
                strtoupper($input['tan_number'] ?? null),
                strtoupper($input['trading_name'] ?? null),
                $input['country_type'] ?? null,
                $input['country_id'] ?? null,
                $input['state_id'] ?? null,
                strtoupper($input['country_text'] ?? null),
                strtoupper($input['state_text'] ?? null),
                $input['telephone'] ?? null,
                strtoupper($input['registered_address'] ?? null),
                strtoupper($input['business_address'] ?? null),
                $input['contact_person_title'] ?? null,
                strtoupper($input['contact_person_name'] ?? null),
                $input['contact_person_email'] ?? null,
                $input['contact_person_mobile'] ?? null,
                $input['accounts_person_title'] ?? null,
                strtoupper($input['accounts_person_name'] ?? null),
                $input['accounts_person_contact_no'] ?? null,
                $input['accounts_person_email'] ?? null,
                $module,
                $username
            );

            if ($result) {
                http_response_code(201);
                $response = ["message" => "Counterparty details added successfully"];
            } else {
                http_response_code(500);
                $response = ["error" => "Failed to add counterparty details"];
            }
        } catch (Exception $e) {
            http_response_code(500);
            $response = ["error" => "Server error: " . $e->getMessage()];
            $logger->log("Error inserting vendor: " . $e->getMessage(), 'api', $module, $username);
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response, 'api', $module, $username);
        break;

    case 'PUT':
        $logger->log("PUT request received", 'api', $module, $username);

        // Validate Reference ID
        if (!isset($_GET['reference_id']) || empty($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "Reference ID must be provided in URL parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input ?? []), $error, 'api', $module, $username);
            exit;
        }

        $reference_id = $_GET['reference_id'];

        // Required fields for PUT request
        $requiredFields = ['full_registered_name', 'business_entity_type', 'registered_address'];

        // Validate required fields
        foreach ($requiredFields as $field) {
            if (!isset($input[$field]) || empty(trim($input[$field]))) {
                http_response_code(400);
                $error = ["error" => "Required field '$field' is missing or empty"];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input ?? []), $error, 'api', $module, $username);
                exit;
            }
        }

        // Validate business entity type
        $validEntityTypes = [
            'Sole Proprietorship',
            'Partnership',
            'Limited Liability Partnership',
            'Public Limited Companies',
            'Private Limited Companies',
            'One-Person Companies',
            'Section 8 Company',
            'Joint-Venture Company',
            'Non-Government Organization(NGO)'
        ];

        if (!in_array($input['business_entity_type'], $validEntityTypes)) {
            http_response_code(400);
            $error = ["error" => "Invalid business entity type"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error, 'api', $module, $username);
            exit;
        }

        try {

            if($input['country_type'] == 'India'){
                // Make country_text and state_text null for India
                $input['country_text'] = null;
                $input['state_text'] = null;
                // Validate country_id and state_id for India
                if (empty($input['country_id']) || empty($input['state_id'])) {
                    http_response_code(400);
                    $error = ["error" => "country_id and state_id are required for country_type 'India'"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(array_merge($_GET, $input), $error, 'api', $module, $username);
                    exit;
                }
            } else {
                // For other countries, country_id and state_id should be null
                $input['country_id'] = null;
                $input['state_id'] = null;
                // Validate country_text for other countries
                if (empty($input['country_text']) || empty($input['state_text'])) {
                    http_response_code(400);
                    $error = ["error" => "country_text and state_text are required for country_type other than 'India'"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(array_merge($_GET, $input), $error, 'api', $module, $username);
                    exit;
                }
            }

            $counterPartyInfo = $vendorOb->getCounterpartyByReferenceId($reference_id, $module, $username);
            if(!$counterPartyInfo){
                http_response_code(404);
                $error = ["error" => "Counterparty not found for the given reference id"];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error, 'api', $module, $username);
                exit;
            }

            // Update vendor
            $result = $vendorOb->updateCounterparty(
                $reference_id,
                strtoupper($input['full_registered_name'] ?? null),
                strtoupper($input['business_entity_type'] ?? null),
                strtoupper($input['reg_number'] ?? null),
                strtoupper($input['tan_status'] ?? null),
                strtoupper($input['tan_number'] ?? null),
                strtoupper($input['trading_name'] ?? null),
                $input['country_type'] ?? null,
                $input['country_id'] ?? null,
                $input['state_id'] ?? null,
                strtoupper($input['country_text'] ?? null),
                strtoupper($input['state_text'] ?? null),
                $input['telephone'] ?? null,
                strtoupper($input['registered_address'] ?? null),
                strtoupper($input['business_address'] ?? null),
                $input['contact_person_title'] ?? null,
                strtoupper($input['contact_person_name'] ?? null),
                $input['contact_person_email'] ?? null,
                $input['contact_person_mobile'] ?? null,
                $input['accounts_person_title'] ?? null,
                strtoupper($input['accounts_person_name'] ?? null),
                $input['accounts_person_contact_no'] ?? null,
                $input['accounts_person_email'] ?? null,
                $module,
                $username
            );

            if ($result !== false) {
                http_response_code(200);
                $response = ["message" => $result > 0 ? "Vendor updated successfully" : "No changes made"];
            } else {
                http_response_code(500);
                $response = ["error" => "Failed to update vendor"];
            }
        } catch (Exception $e) {
            http_response_code(500);
            $response = ["error" => "Server error: " . $e->getMessage()];
            $logger->log("Error updating vendor: " . $e->getMessage(), 'api', $module, $username);
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input ?? []), $response, 'api', $module, $username);
        break;

    case 'DELETE':
        $logger->log("DELETE request received", 'api', $module, $username);

        // Validate Vendor ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Vendor ID must be provided and numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error, 'api', $module, $username);
            exit;
        }

        try {
            $vendor_id = intval($_GET['id']);
            $result = $vendorOb->deleteVendor($vendor_id, $module, $username);

            if ($result > 0) {
                http_response_code(200);
                $response = ["message" => "Vendor deleted successfully"];
            } else {
                http_response_code(404);
                $response = ["error" => "Vendor not found or failed to delete"];
            }
        } catch (Exception $e) {
            http_response_code(500);
            $response = ["error" => "Server error: " . $e->getMessage()];
            $logger->log("Error deleting vendor: " . $e->getMessage(), 'api', $module, $username);
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response, 'api', $module, $username);
        break;

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error, 'api', $module, $username);
        break;
}
