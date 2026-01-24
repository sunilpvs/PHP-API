<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}



require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';
require_once __DIR__ . '../../../classes/admin/AccessRequest.php';

// Authenticate using JWT
authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$accessRequest = new AccessRequest();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$email = $auth->getEmailFromJWT();
$module = 'Access Request';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $accessRequest->getAccessRequestById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Access request not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] === 'pending_count') {
            $data = $accessRequest->getPendingAccessRequestsCount($module, $username);
            http_response_code(200);
            echo json_encode(['pending_count' => $data]);
            $logger->logRequestAndResponse($_GET, ['pending_count' => $data]);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] === 'req_count') {
            $data = $accessRequest->getAccessRequestsCount($module, $username);
            http_response_code(200);
            echo json_encode(['req_count' => $data]);
            $logger->logRequestAndResponse($_GET, ['req_count' => $data]);
            break;
        }


        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $data = $accessRequest->getAllAccessRequests($module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] === 'pending') {
            $data = $accessRequest->getPendingAccessRequests($module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        if(isset($_GET['type']) && $_GET['type'] === 'all_users') {
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
            $offset = ($page - 1) * $limit;

            $data = $accessRequest->getPaginatedUsers($module, $username, $limit, $offset);
            $total = $accessRequest->getTotalUsersCount($module, $username);

            $response = [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'users' => $data,
            ];

            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $accessRequest->getPaginatedAccessRequests($module, $username, $limit, $offset);
        $total = $accessRequest->getAccessRequestsCount($module, $username);


        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'requests' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");
        $contactId = $accessRequest->getContactIdfromEmail($email, $module, $username);

        if (!$contactId) {
            http_response_code(400);
            $error = ["error" => "Contact ID not found for the user"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }


        // Validation: Check if all required fields are present
        // $required = ['requested_module'];
        // foreach ($required as $field) {
        //     if (empty($input[$field])) {
        //         http_response_code(400);
        //         $error = ["error" => ucfirst(str_replace('_', ' ', $field)) . " is required"];
        //         echo json_encode($error);
        //         $logger->logRequestAndResponse($input, $error);
        //         return;
        //     }
        // }

        $requested_module = $input['requested_module'] ?? null;
        if(!$requested_module){
            http_response_code(400);
            $error = ["error" => "Requested module is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            return;
        }

        if($requested_module == 1){
            $existingAdminRequest = $accessRequest->checkExistingAdminRequest($email, $module, $username);
            if ($existingAdminRequest > 0) {
                http_response_code(400);
                $error = ["error" => "Request already submitted. Please wait for approval."];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        } else if($requested_module == 4){
            $existingVmsRequest = $accessRequest->checkExistingVmsRequest($email, $module, $username);
            if ($existingVmsRequest > 0) {
                http_response_code(400);
                $error = ["error" => "Request already submitted. Please wait for approval."];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
                return;
            }
        }

         if (!isset($input['requested_module']) || !in_array($input['requested_module'], [1, 4, 3])) {
            http_response_code(400);
            $error = ["error" => "Invalid requested module. Use 1 for Admin  or 3 for WMS or 4 for VMS"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $result = $accessRequest->insertAccessRequest(
            $email,
            $contactId,
            $requested_module,
            $module,
            $username
        );
        
        if ($result ) {
            http_response_code(201);
            $response = ["message" => "Access request created successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to create access request"];

        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

    case 'PUT':
        $logger->log("PUT request received");
        $user_role_id = null;

        

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Access request ID is required and must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $id = intval($_GET['id']);

        if (!isset($input['status']) || !in_array($input['status'], [11, 12])) {
            http_response_code(400);
            $error = ["error" => "Invalid status. Use 11 for approved or 12 for rejected"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $userRole = $accessRequest->getRoleIdFromEmail($email, $module, $username);
        if (!in_array($userRole, [1, 2])) {
            http_response_code(403);
            $error = ["error" => "You do not have permission to update access request status"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $existingRequest = $accessRequest->getAccessRequestById($id, $module, $username);

        $status = $input['status'];
        if($status == 11){
            if (!isset($input['user_role']) || !in_array($input['user_role'], [2, 6, 7])) {
                http_response_code(400);
                $error = ["error" => "Invalid user role. Use 2 for IT Admin or 6 for VMS ADMIN or 7 for VMS MANAGEMENT"];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                break;
            }
            $user_role_id = $input['user_role'];
            if($existingRequest['requested_module'] == 1 && !in_array($user_role_id, [2])){
                http_response_code(400);
                $error = ["error" => "For Admin module, only IT Admin role (2) can be assigned."];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                break;
            }

            if($existingRequest['requested_module'] == 4 && !in_array($user_role_id, [6,7])){
                http_response_code(400);
                $error = ["error" => "For VMS module, only VMS ADMIN (6) or VMS MANAGEMENT (7) roles can be assigned."];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                break;
            }            
            
        }
        

        
        if($existingRequest){
            if($existingRequest['status'] == 11 || $existingRequest['status'] == 12){
                http_response_code(400);
                $error = ["error" => "This access request has already been processed."];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
                break;
            }
        }


        $result = $accessRequest->updateAccessRequestStatus($id, $user_role_id, $status, $module, $email);

        if ($result) {
            http_response_code(200);
            $response = ["message" => "Access request status updated successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to update access request status"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (empty($input['email']) || empty($input['user_module'])) {
            http_response_code(400);
            $error = ["error" => "Email and User Module ID are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $emailToDelete = $input['email'];
        $user_module_id = (int)$input['user_module'];

        $validModules = [1, 2, 3, 4]; // 1=Admin, 3=WMS, 4=VMS
        if (!in_array($user_module_id, $validModules, true)) {
            http_response_code(400);
            $error = [
                "error" => "Invalid User Module. Use 1 for Admin, 3 for WMS, or 4 for VMS, 2 for DEFAULT"
            ];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $doesUserExist = $accessRequest->checkUserModuleExist(
            $emailToDelete,
            $user_module_id,
            $module,
            $username
        );

        if (!$doesUserExist) {
            http_response_code(404);
            $error = ["error" => "User module access not found"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if($emailToDelete === $email) {
            http_response_code(400);
            $error = ["error" => "You cannot delete your own Admin access"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // if($user_module_id == 1) {
        //     $adminCount = $accessRequest->countAdminsExcludingUser(
        //         $emailToDelete,
        //         $module,
        //         $username
        //     );

        //     if ($adminCount <= 0) {
        //         http_response_code(400);
        //         $error = ["error" => "Cannot delete the only remaining Admin user"];
        //         echo json_encode($error);
        //         $logger->logRequestAndResponse($input, $error);
        //         break;
        //     }
        // }

        if($user_module_id == 2) {
            http_response_code(400);
            $error = ["error" => "Default module access cannot be deleted"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $accessRequest->deleteUser(
            $emailToDelete,
            $user_module_id,
            $module,
            $username
        );

        if ($result) {
            http_response_code(200);
            $response = ["message" => "User module access deleted successfully"];
        } else {
            http_response_code(500);
            $response = ["error" => "Failed to delete user module access"];
        }

        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;


    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"];
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
?>

