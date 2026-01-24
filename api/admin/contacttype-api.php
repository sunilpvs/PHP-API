<?php



if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/admin/Contacttype.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';

authenticateJWT(); // Uncomment if you want JWT auth here

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$contactType = new ContactType();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $contactType->getContactTypeById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Contact Type not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            // Provide id and name for combo list
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'name'];
            $fields = array_map('trim', $fields);
            // getAllContactTypes returns all fields; we'll filter to requested fields manually
            $all = $contactType->getAllContactTypes($module, $username);
            $comboData = [];
            foreach ($all as $row) {
                $item = [];
                foreach ($fields as $field) {
                    if (isset($row[$field])) {
                        $item[$field] = $row[$field];
                    }
                }
                $comboData[] = $item;
            }
            http_response_code(200);
            echo json_encode($comboData);
            $logger->logRequestAndResponse($_GET, $comboData);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $contactType->getPaginatedContactTypes($offset, $limit, $module, $username);
        $total = $contactType->getContactTypesCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'contact_types' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($input['name']) || empty(trim($input['name']))) {
            http_response_code(400);
            $error = ["error" => "Name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!isset($input['status']) || empty(trim($input['status']))) {
            http_response_code(400);
            $error = ["error" => "Status is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $name = trim($input['name']);
        $status = trim($input['status']);

        // Simple validation example (only letters and spaces for name)
        if (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            http_response_code(400);
            $error = ["error" => "Name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // Check duplicate by name
        $existing = $contactType->getContactTypeByName($name, $module, $username);
        if ($existing) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Contact Type already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $contactType->insertContactType($name, $status, $module, $username);

        if ($result) {
            http_response_code(201);
            $response = ["message" => "Contact Type added successfully", "id" => (int)$result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to add Contact Type"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Contact Type ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($input['name']) || empty(trim($input['name']))) {
            http_response_code(400);
            $error = ["error" => "Name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($input['status']) || empty(trim($input['status']))) {
            http_response_code(400);
            $error = ["error" => "Status is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $id = intval($_GET['id']);
        $name = trim($input['name']);
        $status = trim($input['status']);

        if (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            http_response_code(400);
            $error = ["error" => "Name must contain only alphabets and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        // Check duplicate except self
        $existing = $contactType->getContactTypeByName($name, $module, $username);
        if ($existing && $existing['id'] != $id) {
            http_response_code(400);
            $error = ["error" => "Duplicate Record: Contact Type already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $result = $contactType->updateContactType($id, $name, $status, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Contact Type updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update Contact Type"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Contact Type ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);
        $result = $contactType->deleteContactType($id, $module, $username);

        if ($result > 0) {
            http_response_code(200);
            $response = ["message" => "Contact Type deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete Contact Type"];
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
