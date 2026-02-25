<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/vms/Rfq.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use \PhpOffice\PhpSpreadsheet\IOFactory;

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$rfqOb = new Rfq();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'RFQ';

$method = $_SERVER['REQUEST_METHOD'];

function normalizeHeader($header)
{
    return strtolower(trim((string)$header));
}

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $rfqOb->getRfqById($id, $module, $username);
            $statusCode = $data ? 200 : 404;
            $response = $data ?: ["error" => "RFQ not found"];
            http_response_code($statusCode);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $rfqOb->getPaginatedRfqs($offset, $limit, $module, $username);
        $total = $rfqOb->getRfqsCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rfqs' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log("POST request received");

        $fileKey = null;
        if (isset($_FILES['excel_file'])) {
            $fileKey = 'excel_file';
        } elseif (isset($_FILES['file'])) {
            $fileKey = 'file';
        }

        if (!$fileKey || !isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            $error = ["error" => "Excel file upload failed. Use excel_file or file field."];
            echo json_encode($error);
            $logger->logRequestAndResponse($_FILES, $error);
            break;
        }

        $fileName = $_FILES[$fileKey]['name'];
        $fileTmp = $_FILES[$fileKey]['tmp_name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            http_response_code(400);
            $error = ["error" => "Only .xlsx or .xls files are allowed"];
            echo json_encode($error);
            $logger->logRequestAndResponse(["file" => $fileName], $error);
            break;
        }

        try {
            $spreadsheet = IOFactory::load($fileTmp);
            $sheet = $spreadsheet->getSheetByName('Dump_Data');

            if (!$sheet) {
                http_response_code(400);
                $error = ["error" => "Sheet 'Dump_Data' not found in uploaded file"];
                echo json_encode($error);
                $logger->logRequestAndResponse(["file" => $fileName], $error);
                break;
            }

            $rows = $sheet->toArray(null, true, true, false);
            if (empty($rows) || !isset($rows[0])) {
                http_response_code(400);
                $error = ["error" => "Excel sheet Dump_Data is empty"];
                echo json_encode($error);
                $logger->logRequestAndResponse(["file" => $fileName], $error);
                break;
            }

            $headerMap = [];
            foreach ($rows[0] as $index => $headerValue) {
                $headerMap[normalizeHeader($headerValue)] = $index;
            }

            $requiredColumns = ['vendor_name', 'contact_name', 'email', 'mobile', 'entity_id', 'status_id'];
            foreach ($requiredColumns as $column) {
                if (!array_key_exists($column, $headerMap)) {
                    http_response_code(400);
                    $error = ["error" => "Required column missing in Dump_Data sheet: $column"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(["file" => $fileName], $error);
                    break 2;
                }
            }

            $inserted = 0;
            $skippedEmpty = 0;
            $skippedInvalid = 0;
            $skippedDuplicate = 0;
            $rowErrors = [];

            for ($i = 1; $i < count($rows); $i++) {
                $rowNumber = $i + 1;
                $row = $rows[$i];

                $vendor_name = trim((string)($row[$headerMap['vendor_name']] ?? ''));
                $contact_name = trim((string)($row[$headerMap['contact_name']] ?? ''));
                $email = trim((string)($row[$headerMap['email']] ?? ''));
                $mobile = trim((string)($row[$headerMap['mobile']] ?? ''));
                $entity_id_raw = trim((string)($row[$headerMap['entity_id']] ?? ''));
                $status_raw = trim((string)($row[$headerMap['status_id']] ?? ''));

                if ($vendor_name === '' && $contact_name === '' && $email === '' && $mobile === '' && $entity_id_raw === '' && $status_raw === '') {
                    $skippedEmpty++;
                    continue;
                }

                if ($vendor_name === '' || $contact_name === '' || $email === '' || $mobile === '' || $entity_id_raw === '' || $status_raw === '') {
                    $skippedInvalid++;
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'reason' => 'One or more required fields are empty'
                    ];
                    continue;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skippedInvalid++;
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'reason' => 'Invalid email format'
                    ];
                    continue;
                }

                if (!ctype_digit($entity_id_raw)) {
                    $skippedInvalid++;
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'reason' => 'entity_id must be numeric'
                    ];
                    continue;
                }

                if (!ctype_digit($status_raw)) {
                    $skippedInvalid++;
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'reason' => 'status_id must be numeric'
                    ];
                    continue;
                }

                $entity_id = (int)$entity_id_raw;
                $status = (int)$status_raw;

                if ($rfqOb->checkDuplicateRfq($vendor_name, $email, $mobile, $entity_id)) {
                    $skippedDuplicate++;
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'reason' => 'Duplicate RFQ already exists'
                    ];
                    continue;
                }

                $result = $rfqOb->insertRfqWithoutMails(
                    $vendor_name,
                    $contact_name,
                    $email,
                    $mobile,
                    $entity_id,
                    $username,
                    $module,
                    $username,
                    $status
                );

                if ($result) {
                    $inserted++;
                } else {
                    $skippedInvalid++;
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'reason' => 'Insert failed'
                    ];
                }
            }

            $response = [
                'message' => 'RFQ bulk import completed',
                'sheet' => 'Dump_Data',
                'inserted' => $inserted,
                'skipped_empty' => $skippedEmpty,
                'skipped_invalid' => $skippedInvalid,
                'skipped_duplicate' => $skippedDuplicate,
                'row_errors' => $rowErrors
            ];

            http_response_code(200);
            echo json_encode($response);
            $logger->logRequestAndResponse(["file" => $fileName], $response);
            break;
        } catch (Exception $e) {
            http_response_code(500);
            $error = ["error" => "Failed to process Excel file"];
            echo json_encode($error);
            $logger->log('RFQ bulk import error: ' . $e->getMessage(), 'api', $module, $username);
            $logger->logRequestAndResponse(["file" => $fileName], $error);
            break;
        }

    default:
        http_response_code(405);
        $error = ["error" => "Method not allowed"]; 
        echo json_encode($error);
        $logger->logRequestAndResponse($_SERVER, $error);
        break;
}
