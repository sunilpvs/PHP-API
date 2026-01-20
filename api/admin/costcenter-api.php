<?php


if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/admin/Costcenter.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';

// Authenticate request
authenticateJWT();

// Logger setup
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$costCenter = new CostCenter();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'Admin';

try {
    switch ($method) {
        case 'GET':
            $logger->log("GET request received");

            // Single cost center by ID
            if (isset($_GET['id'])) {
                $id = intval($_GET['id']);
                $data = $costCenter->getCostCenterById($id, $module, $username);
                $status = $data ? 200 : 404;
                http_response_code($status);
                echo json_encode($data ?: ["error" => "Cost center not found"]);
                $logger->logRequestAndResponse($_GET, $data ?: ["error" => "Cost center not found"]);
                break;
            }


            if(isset($_GET['type']) && $_GET['type'] === 'count') {
                $data = $costCenter->getCostCentersCount($module, $username);
                http_response_code(200);
                echo json_encode(['total' => $data]);
                $logger->logRequestAndResponse($_GET, ['count' => $data]);
                break;
            }

            // Pagination parameters
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
            $offset = ($page - 1) * $limit;

            $data = $costCenter->getPaginatedCostCenters($offset, $limit, $module, $username);
            $total = $costCenter->getCostCentersCount($module, $username);

            $response = [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'costCenters' => $data,
            ];

            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;

        case 'POST':
            $logger->log("POST request received");

            // Required fields for creating a cost center
            $required = ['cc_code', 'entity_id', 'incorp_date', 'gst_no', 'add1', 'city', 'state', 'pin', 'country', 'primary_contact', 'status'];

            // Check if all required fields are present
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    http_response_code(400);
                    $error = ["error" => "Field '{$field}' is required"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse($input, $error);
                    return;
                }
            }

            // Sanitize and assign the values from the input
            $cc_code = trim($input['cc_code']);
            $cc_type = 2;                               // Default cost center type - Branch-Office
            $entity_id = intval($input['entity_id']);
            $incorp_date = trim($input['incorp_date']);
            $gst_no = trim($input['gst_no']);
            $add1 = trim($input['add1']);
            $add2 = isset($input['add2']) ? trim($input['add2']) : '';
            $city = intval($input['city']);
            $state = intval($input['state']);
            $country = intval($input['country']);
            $pin = trim($input['pin']);
            $primary_contact = intval($input['primary_contact']);
            $status = trim($input['status']);

            // Call the addCostCenter method to insert the cost center into the database
            $insertId = $costCenter->addCostCenter(
                $cc_code,
                $cc_type,
                $entity_id,
                $incorp_date,
                $gst_no,
                $add1,
                $add2,
                $city,
                $state,
                $country,
                $pin,
                $primary_contact,
                $status,
                $module,
                $username
            );

            // Respond with success and the newly created cost center ID
            http_response_code(201);
            $response = ["message" => "Cost Center created", "id" => $insertId];
            echo json_encode($response);

            // Log the request and response
            $logger->logRequestAndResponse($input, $response);
            break;


        case 'PUT':
            $logger->log("PUT request received");

            if (!isset($_GET['id'])) {
                throw new Exception("Cost Center ID is required for update");
            }
            $id = intval($_GET['id']);

            $required = ['cc_code', 'entity_id', 'incorp_date', 'gst_no', 'add1', 'city', 'state', 'country', 'pin', 'primary_contact', 'status'];
            // Check if all required fields are present
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    http_response_code(400);
                    $error = ["error" => "Field '{$field}' is required"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                    return;
                }
            }

            $entity_id = $costCenter->getEntityIdFromCostCenter($id, $module, $username);
            if ($entity_id === null) {
                http_response_code(404);
                $error = ["error" => "Cost Center not found"];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                return;
            }

            $cc_code = trim($input['cc_code']);
            // $cc_type = 2;                               // Default cost center type - Branch-Office
            // $entity_id = intval($input['entity_id']);
            $incorp_date = trim($input['incorp_date']);
            $gst_no = trim($input['gst_no']);
            $add1 = trim($input['add1']);
            $add2 = isset($input['add2']) ? trim($input['add2']) : '';
            $city = intval($input['city']);
            $state = intval($input['state']);
            $country = intval($input['country']);
            $pin = trim($input['pin']);
            $primary_contact = intval($input['primary_contact']);
            $status = trim($input['status']);

            $res = $costCenter->editCostCenter(
                $cc_code,
                $incorp_date,
                $gst_no,
                $add1,
                $add2,
                $city,
                $state,
                $country,
                $pin,
                $primary_contact,
                $status,
                $id,
                $entity_id,
                $module,
                $username
            );

            http_response_code(200);
            $response = ["message" => "Cost Center updated"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
            break;

        case 'DELETE':
            $logger->log("DELETE request received");

            if (!isset($_GET['id'])) {
                throw new Exception("Cost Center ID is required for deletion");
            }
            $id = intval($_GET['id']);
            $res = $costCenter->deleteCostCenter($id, $module, $username);

            http_response_code(200);
            $response = ["message" => "Cost Center deleted"];
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
} catch (Exception $e) {
    http_response_code(400);
    $error = ["error" => $e->getMessage()];
    echo json_encode($error);
    $logger->logRequestAndResponse(['error' => $e->getMessage()], []);
}

?>
