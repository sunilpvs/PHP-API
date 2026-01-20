<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Documents.php';
require_once __DIR__ . '../../../classes/vms/CounterPartyInfo.php';

// Authenticate using JWT
authenticateJWT();


$method = $_POST['_method'] ?? $_GET['_method'] ?? $_SERVER['REQUEST_METHOD'];
$method = strtoupper($method);

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['DEBUG_MODE']) && in_array(strtolower($config['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$docOb = new Documents();
$counterPartyInfoOb = new CounterPartyInfo();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'DOCUMENTS';

// $method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);




switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $docId = intval($_GET['id']);
            $data = $docOb->getDocumentById($docId, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Document not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['reference_id'])) {
            $referenceId = $_GET['reference_id'];
            $data = $docOb->getDocumentsByReferenceId($referenceId, $module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        // Paginated documents response
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $docOb->getPaginatedDocuments($offset, $limit, $module, $username);
        $total = $docOb->getDocumentsCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'documents' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            echo json_encode(["error" => "Reference ID is required"]);
            break;
        }

        $referenceId = $_GET['reference_id'];
        $uploadDir = __DIR__ . "/../../uploads/vendor_reference/$referenceId/documents/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        // Get the doc types and files from POST
        $docTypes = $_POST['doc_types'] ?? [];
        $files    = $_FILES['files'] ?? null;
        $deleteDocIds = $_POST['delete_doc_ids'] ?? [];

        if (!is_array($docTypes)) $docTypes = [$docTypes];
        if (!is_array($deleteDocIds)) $deleteDocIds = [$deleteDocIds];

        $docTypes = array_values($docTypes);
        $deleteDocIds = array_values($deleteDocIds);

        $fileNames = $tmpNames = $errors = [];
        if ($files && isset($files['name'])) {
            $fileNames = array_values((array)$files['name']);
            $tmpNames  = array_values((array)$files['tmp_name']);
            $errors    = array_values((array)$files['error']);
        }

        $logger->log("POST Received - docTypes: " . json_encode($docTypes));
        $logger->log("DELETE IDs: " . json_encode($deleteDocIds));

        // Load existing documents for this reference ID
        $existingDocs = $docOb->getDocumentsByReferenceId($referenceId, $module, $username);

        $existingByType = [];
        $existingById   = [];

        foreach ($existingDocs as $ed) {
            $existingByType[$ed['doc_type']] = $ed;
            $existingById[(int)$ed['doc_id']] = $ed;
        }

        $responses = [];

        // ---------------------------------------------
        // HANDLE DELETIONS FIRST
        // ---------------------------------------------
        foreach ($deleteDocIds as $docId) {
            $docId = (int)$docId;

            if (!isset($existingById[$docId])) {
                $responses[] = ["doc_id" => $docId, "error" => "Document not found"];
                continue;
            }

            $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $existingById[$docId]['file_path'];

            // Delete file from filesystem
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }

            // Delete from database
            $deleted = $docOb->deleteDocument($docId, $module, $username);

            if ($deleted) {
                $responses[] = ["doc_id" => $docId, "status" => "deleted"];
            } else {
                $responses[] = ["doc_id" => $docId, "error" => "DB delete failed"];
            }
        }

        // ---------------------------------------------
        // PROCESS FILES
        // ---------------------------------------------
        foreach ($fileNames as $i => $name) {

            if ($errors[$i] !== UPLOAD_ERR_OK) {
                $responses[] = ["index" => $i, "error" => "File upload error"];
                continue;
            }

            $docType = $docTypes[$i] ?? null;
            if (!$docType) {
                $responses[] = ["index" => $i, "error" => "Missing doc_type"];
                continue;
            }

            $newName = time() . "_" . basename($name);
            $target  = $uploadDir . $newName;

            if (!move_uploaded_file($tmpNames[$i], $target)) {
                $responses[] = ["index" => $i, "error" => "Failed to move file"];
                continue;
            }

            $newPath = "uploads/vendor_reference/$referenceId/documents/$newName";

            // UPDATE (doc_type exists)
            if (isset($existingByType[$docType])) {

                $docId = (int)$existingByType[$docType]['doc_id'];
                $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $existingByType[$docType]['file_path'];

                if (file_exists($oldPath)) @unlink($oldPath);

                $ok = $docOb->updateDocument(
                    $docId,
                    $referenceId,
                    $docType,
                    $newPath,
                    $module,
                    $username
                );

                if ($ok) {
                    $responses[] = ["doc_id" => $docId, "status" => "updated"];
                } else {
                    @unlink($target);
                    $responses[] = ["doc_id" => $docId, "error" => "DB update failed"];
                }
            }
            // INSERT new document
            else {

                $newId = $docOb->insertDocument(
                    $referenceId,
                    $docType,
                    $newPath,
                    $module,
                    $username
                );

                if ($newId) {
                    $responses[] = ["doc_id" => $newId, "status" => "inserted"];
                } else {
                    @unlink($target);
                    $responses[] = ["doc_type" => $docType, "error" => "DB insert failed"];
                }
            }
        }

        http_response_code(200);
        echo json_encode([
            "message" => "Processed documents",
            "details" => $responses
        ]);
        break;


    case 'PUT':
        $logger->log("PUT request received (via method override)");

        if (!isset($_GET['reference_id'])) {
            http_response_code(400);
            echo json_encode(["error" => "Reference ID is required"]);
            break;
        }

        $referenceId = $_GET['reference_id'];

        if (!isset($_FILES['files']) || !isset($_POST['doc_ids']) || !isset($_POST['doc_types'])) {
            http_response_code(400);
            echo json_encode(["error" => "Files, doc_ids and doc_types are required"]);
            break;
        }

        $files = $_FILES['files'];
        $docIds = $_POST['doc_ids'];
        $docTypes = $_POST['doc_types'];

        if (!is_array($files['name'])) {
            http_response_code(400);
            echo json_encode(["error" => "files must be an array"]);
            break;
        }



        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/vendor_reference/$referenceId/documents/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $responses = [];

        foreach ($files['name'] as $i => $name) {
            $docId = intval($docIds[$i]);
            $docType = $docTypes[$i];

            $existingDoc = $docOb->getDocumentById($docId, $module, $username);
            if (!$existingDoc) {
                $responses[] = ["doc_id" => $docId, "error" => "Document not found"];
                continue;
            }

            // DELETE ALL old files belonging to this document
            $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $existingDoc['file_path'];

            if (file_exists($oldPath)) {
                unlink($oldPath);
            }

            // Also remove previous uploads with same doc type (PAN, GST etc.)
            $pattern = $uploadDir . '*' . $existingDoc['doc_type'] . '*';
            foreach (glob($pattern) as $oldFile) {
                if (is_file($oldFile)) unlink($oldFile);
            }


            // Upload new file
            $fileName = time() . "_" . basename($name);
            $targetPath = $uploadDir . $fileName;

            if (!move_uploaded_file($files['tmp_name'][$i], $targetPath)) {
                $responses[] = ["doc_id" => $docId, "error" => "Failed to upload"];
                continue;
            }

            $dbFilePath = "uploads/vendor_reference/$referenceId/documents/$fileName";

            // Update DB
            $updated = $docOb->updateDocument($docId, $referenceId, $docType, $dbFilePath, $module, $username);

            $responses[] = $updated
                ? ["doc_id" => $docId, "status" => "updated", "file_path" => $dbFilePath]
                : ["doc_id" => $docId, "error" => "DB update failed"];
        }

        echo json_encode(["results" => $responses]);
        break;


    case 'DELETE':
        $logger->log("DELETE request received");

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Document ID is required and must be numeric"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $docId = intval($_GET['id']);
        $result = $docOb->deleteDocument($docId, $module, $username);

        $response = $result > 0
            ? ["message" => "Document deleted successfully"]
            : ["error" => "Failed to delete document"];

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
