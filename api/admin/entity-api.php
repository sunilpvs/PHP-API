<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/admin/Entity.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

// Allow combo endpoint without authentication (needed for login form)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['type']) && $_GET['type'] === 'combo' 
        && isset($_GET['fields']) && $_GET['fields'] === 'id,entity_name') {
    // Allow access without authentication
} else {
    // Authenticate JWT for all other endpoints
    authenticateJWT();
}

// Load config
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1','true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$entityOb = new Entity();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        // New route: /entity-api.php?primary_contacts=1
        if (isset($_GET['primary_contacts'])) {
            $data = $entityOb->getPrimaryContacts($module, $username);
            http_response_code(200);
            echo json_encode(['contacts' => $data]);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $entityOb->getEntityById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Entity not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        // if type = combo and fields = id,name then return id and name pairs and make it available for dropdowns
        // to be accessed in login form without authentication
        if (isset($_GET['type']) && $_GET['type'] === 'combo' && isset($_GET['fields']) 
                && $_GET['fields'] === 'id,entity_name') {
            $data = $entityOb->getEntityCombo($module, $username);
            http_response_code(200);
            echo json_encode(['entities' => $data]);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        $data = $entityOb->getPaginatedEntities($offset, $limit, $module, $username);
        $total = $entityOb->getEntitiesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'entities' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        $required = ['entity_name','cin','incorp_date','status'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty(trim($input[$field]))) {
                http_response_code(400);
                $error = ["error" => "Field '{$field}' is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                break 2;
            }
        }

        $entity_name = trim($input['entity_name']);
        $cc_code = trim($input['cc_code'] );
        $cin = trim($input['cin']);
        $incorp_date = trim($input['incorp_date']);
        $gst_no = trim($input['gst_no']) ;
        $add1 = trim($input['add1']);
        $add2 = trim($input['add2']);
        $city = intval(trim($input['city_id']));
        $state = intval(trim($input['state_id']));
        $country = intval(trim($input['country_id']));
        $pin = isset($input['pin']) ? trim($input['pin']) : '';
        $salutation_name = trim($input['salutation_name']);

        $status = trim($input['status']);
        $primary_contact = isset($input['primary_contact']) ? intval($input['primary_contact']) : null;

        if ($entityOb->checkDuplicateEntityName($entity_name)) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Entity name already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }
        if ($entityOb->checkDuplicateCin($cin)) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: CIN already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }


        $result = $entityOb->addEntity($entity_name, $cc_code, $cin, $incorp_date, $gst_no, $add1, $add2, $city, $state, $country, $pin, $primary_contact, $salutation_name, $status, $module, $username);
        if ($result) {
            http_response_code(201);
            $response = ["message" => "Entity added successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add entity"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Entity ID is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $required = ['entity_name','cin','incorp_date','salutation_name','status'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty(trim($input[$field]))) {
                http_response_code(400);
                $error = ["error" => "Field '{$field}' is required"];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                break 2;
            }
        }

        $entity_name = trim($input['entity_name']);
        $cin = trim($input['cin']);
        $incorp_date = trim($input['incorp_date']);
        $salutation_name = trim($input['salutation_name']);
        $status = trim($input['status']);

        if ($entityOb->checkEditDuplicateEntityName($entity_name, $id)) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Entity name already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }
        if ($entityOb->checkEditDuplicateCin($cin, $id)) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: CIN already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $result = $entityOb->updateEntity($entity_name, $cin, $incorp_date, $salutation_name, $status, $id, $module, $username);
        if ($result !== false) {
            http_response_code(200);
            $response = ["message" => $result > 0 ? "Entity updated successfully" : "No changes made"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to update Entity"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Entity ID is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $entityOb->deleteEntity($id, $module, $username);
        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Entity deleted successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to delete entity"];
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
?>
