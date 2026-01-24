<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Comments.php';

// Authenticate using JWT
authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$commentOb = new Comments();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'COMMENTS';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is required as a query parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $reference_id = ($_GET['reference_id']);

        if(!isset($_GET['type'])) {
            http_response_code(400);
            $error = ["error" => "type parameter is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $type = $_GET['type'] ?? 'latest';

        if ($type === 'latest') {
            $result = $commentOb->getLatestCommentsByReferenceId($reference_id, $module, $username);
        } else if( $type === 'previous') {
            $result = $commentOb->getPreviousCommentsByReferenceId($reference_id, $module, $username);
        } else if( $type === 'all') {
            $result = $commentOb->getAllComments($module, $username);
        } else {
            http_response_code(400);
            $error = ["error" => "Invalid type parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }
        http_response_code(200);
        echo json_encode($result);
        $logger->logRequestAndResponse($_GET, $result);
        break;

    case 'POST':
        $logger->log("POST request received");
        $logger->log("Input: " . json_encode($input));

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "reference_id is required as a query parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $reference_id = ($_GET['reference_id']);

        if (empty($input['step_name']) || empty($input['comment_text'])) {
            http_response_code(400);
            $error = ["error" => "step_name and comment_text are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // Only employees should be able to add or update comments.
        // We assume that the employee role is checked during JWT authentication (authenticateJWT())

        // Insert new comment for a specific step
        $result = $commentOb->insertComments(
            $reference_id,
            $input['step_name'],  // e.g., 'CompanyInfo', 'MSME', etc.
            $input['comment_text'],
            $module,
            $username
        );

        $response = $result
            ? ["message" => "Comment added successfully", "id" => (int)$result]
            : ["error" => "Failed to add comment"];

        http_response_code($result ? 201 : 500);
        echo json_encode($response);
        $logger->logRequestAndResponse($input, $response);
        break;

    case 'PUT':
        $logger->log("PUT request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Comment ID is required and must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET + $input, $error);
            break;
        }

        $comment_id = intval($_GET['id']);

        if (empty($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "Vendor ID is required as a query parameter and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET + $input, $error);
            break;
        }

        $reference_id = ($_GET['reference_id']);

        if (empty($input['step_name']) || empty($input['comment_text'])) {
            http_response_code(400);
            $error = ["error" => "step_name and comment_text are required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET + $input, $error);
            break;
        }

        // Update existing comment for the specific step
        $result = $commentOb->updateComments(
            $reference_id,
            $input['step_name'],
            $input['comment_text'],
            $comment_id,
            $module,
            $username
        );

        $response = $result
            ? ["message" => "Comment updated successfully"]
            : ["error" => "Failed to update comment"];

        http_response_code($result ? 200 : 500);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET + $input, $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Comment ID is required and must be a number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $comment_id = intval($_GET['id']);
        $result = $commentOb->deleteComments($comment_id, $module, $username);

        $response = $result > 0
            ? ["message" => "Comment deleted successfully"]
            : ["error" => "Failed to delete comment"];

        http_response_code($result > 0 ? 200 : 500);
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
