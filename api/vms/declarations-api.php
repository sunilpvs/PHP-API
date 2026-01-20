<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once __DIR__ . '/../../classes/vms/CounterPartyInfo.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Declarations.php';

// Authenticate using JWT
authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$declOb = new Declarations();
$counterPartyInfoOb = new CounterPartyInfo();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'DECLARATION';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && ($_POST['_method'] ?? '') === 'PUT') {
    $method = 'PUT';
}


// $method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['reference_id'])) {
            $id = $_GET['reference_id'];
            $data = $declOb->getDeclarationByReferenceId($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Declaration not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        // If no specific ID or reference_id is provided, return all declarations
        $data = $declOb->getAllDeclarations($module, $username);
        http_response_code(200);
        echo json_encode($data);
        $logger->logRequestAndResponse($_GET, $data);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "Reference ID is required as a query parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET + $_POST, $error);
            break;
        }

        $reference_id = $_GET['reference_id'];

        // Validate required fields
        // $required = ['declaration_text', 'designation', 'place', 'signed_date'];
        // foreach ($required as $field) {
        //     if (empty($_POST[$field])) {
        //         http_response_code(400);
        //         $error = ["error" => ucfirst(str_replace('_', ' ', $field)) . " is required"];
        //         echo json_encode($error);
        //         $logger->logRequestAndResponse($_GET + $_POST, $error);
        //         break;
        //     }
        // }

        $uploadDir = __DIR__ . "/../../uploads/vendor_reference/$reference_id/declarations/";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Handle file upload
        if (!isset($_FILES['signed_file']) || $_FILES['signed_file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            $error = ["error" => "Signed file is required and must be uploaded successfully"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_POST, $error);
            break;
        }

        $fileName = "declaration_" . "_" . basename($_FILES['signed_file']['name']);
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($_FILES['signed_file']['tmp_name'], $targetPath)) {
            http_response_code(500);
            $error = ["error" => "File upload failed"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_POST, $error);
            break;
        }

        // Insert declaration into the database
        $result = $declOb->insertDeclaration(
            $reference_id,
            $_POST['primary_declarant_name'] ?? null,
            $_POST['primary_declarant_designation'] ?? null,
            $_POST['country_declarant_name'] ?? null,
            $_POST['country_declarant_designation'] ?? null,
            $_POST['country_name'] ?? null,
            $_POST['organisation_name'] ?? null,
            "uploads/vendor_reference/$reference_id/declarations/" . $fileName,
            $_POST['place'] ?? null,
            $_POST['signed_date'] ?? null,
            $module,
            $username
        );

        $response = $result
            ? ["message" => "Declaration added successfully", "id" => (int) $result]
            : ["error" => "Failed to add declaration"];

        http_response_code(isset($response['error']) ? 400 : 201);
        echo json_encode($response);
        $logger->logRequestAndResponse($_POST, $response);
        break;

    case 'PUT':
        $logger->log("PUT request received");

        // Validate declaration ID and vendor ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Declaration ID is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET + $input, $error);
            break;
        }

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            $error = ["error" => "Reference ID is required as a query parameter"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET + $input, $error);
            break;
        }

        $declaration_id = intval($_GET['id']);
        $reference_id = $_GET['reference_id'];

        // Validate required fields
        // $required = ['declaration_text', 'authorized_signatory', 'designation', 'place', 'signed_date'];
        // foreach ($required as $field) {
        //     if (empty($input[$field])) {
        //         http_response_code(400);
        //         $error = ["error" => ucfirst(str_replace('_', ' ', $field)) . " is required"];
        //         echo json_encode($error);
        //         $logger->logRequestAndResponse($_GET + $input, $error);
        //         return;
        //     }
        // }

        // Handle file upload if provided - Delete previous file if exists
        $fileName = null;
        if (isset($_FILES['signed_file']) && $_FILES['signed_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . "/../../uploads/vendor_reference/$reference_id/declarations/";

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Delete previous file if exists
            $existingDeclaration = $declOb->getDeclarationByReferenceId($reference_id, $module, $username);
            if ($existingDeclaration && !empty($existingDeclaration['authorized_signatory'])) {
                $oldFilePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $existingDeclaration['authorized_signatory'];
                if (file_exists($oldFilePath) && is_file($oldFilePath)) {
                    unlink($oldFilePath);
                }

            }

            $fileName = "declaration_" . "_" . basename($_FILES['signed_file']['name']);
            $targetPath = $uploadDir . $fileName;

            if (!move_uploaded_file($_FILES['signed_file']['tmp_name'], $targetPath)) {
                http_response_code(500);
                $error = ["error" => "File upload failed"];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET + $input, $error);
                break;
            }
        }




        $result = $declOb->updateDeclaration(
            $reference_id,
            $_POST['primary_declarant_name'] ?? null,
            $_POST['primary_declarant_designation'] ?? null,
            $_POST['country_declarant_name'] ?? null,
            $_POST['country_declarant_designation'] ?? null,
            $_POST['country_name'] ?? null,
            $_POST['organisation_name'] ?? null,
            "uploads/vendor_reference/$reference_id/declarations/" . $fileName,
            $_POST['place'] ?? null,
            $_POST['signed_date'] ?? null,
            $module,
            $username
        );

        $response = $result !== false
            ? ["message" => $result > 0 ? "Declaration updated successfully" : "No changes made"]
            : ["error" => "Failed to update declaration"];

        http_response_code($result !== false ? 200 : 500);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET + $_POST, $response);
        break;

    case 'DELETE':
        $logger->log("DELETE request received");

        // Validate declaration ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Declaration ID is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $declaration_id = intval($_GET['id']);
        $result = $declOb->deleteDeclaration($declaration_id, $module, $username);

        $response = $result > 0
            ? ["message" => "Declaration deleted successfully"]
            : ["error" => "Failed to delete declaration"];

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
?>