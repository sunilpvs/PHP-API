<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/ams/AssetBrands.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';
require_once __DIR__ . '../../../classes/utils/ExcelImportHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use \PhpOffice\PhpSpreadsheet\IOFactory;

//Validate login and authenticate JWT
authenticateJWT();

//Reading app.ini configuration file
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$regExp = '/^[a-zA-Z0-9\s]+$/';
//Front End authorization as Trusted Hosts.

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$brandObj = new AssetBrands();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';

// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $brandObj->getAssetBrandById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Asset Brand not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'brand'];
            $fields = array_map('trim', $fields);
            $data = $brandObj->getAssetBrandsCombo($module, $username);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        $data = $brandObj->getPaginatedAssetBrands($limit, $offset, $module, $username);
        $total = $brandObj->getAssetBrandCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'asset_brands' => $data,
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

        if ($fileKey) {
            if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                $error = ["error" => "Excel file upload failed"];
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
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);

                if (empty($rows) || !isset($rows[1])) {
                    http_response_code(400);
                    $error = ["error" => "Excel file is empty"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(["file" => $fileName], $error);
                    break;
                }

                $headerRow = $rows[1];
                $brandColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'brand');

                if ($brandColumn === null) {
                    http_response_code(400);
                    $error = ["error" => "Brand column not found in header"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(["file" => $fileName], $error);
                    break;
                }

                $analysis = ExcelImportHelper::analyzeColumnValues($rows, $brandColumn, [
                    'regex' => $regExp,
                    'null_values' => ['', 'null'],
                    'null_reason' => 'Brand is empty',
                    'invalid_reason' => 'Brand name can only contain letters and spaces',
                    'duplicate_file_reason' => 'Duplicate brand in file',
                ]);

                $rowErrors = $analysis['errors'];
                $stats = $analysis['stats'];
                $validRows = $analysis['valid_rows'];

                $brandsLower = array_map(function ($row) {
                    return $row['normalized'];
                }, $validRows);

                $existingLower = $brandObj->getExistingBrandsByNames($brandsLower, $module, $username);
                $existingSet = array_fill_keys($existingLower, true);

                $brandsToInsert = [];
                $skippedDuplicateDb = 0;
                foreach ($validRows as $row) {
                    if (isset($existingSet[$row['normalized']])) {
                        $skippedDuplicateDb++;
                        $rowErrors[] = [
                            'row' => $row['row'],
                            'value' => $row['value'],
                            'reason' => 'Brand already exists',
                        ];
                        continue;
                    }
                    $brandsToInsert[] = $row['value'];
                }

                $rowErrors = ExcelImportHelper::sortRowErrors($rowErrors);

                if (!empty($brandsToInsert)) {
                    $inserted = $brandObj->insertBatchAssetBrandsFromExcel($brandsToInsert, $username);
                    if (!$inserted) {
                        http_response_code(500);
                        $error = ["error" => "Failed to import asset brands"];
                        echo json_encode($error);
                        $logger->logRequestAndResponse(["file" => $fileName], $error);
                        break;
                    }
                }

                http_response_code(200);
                $response = [
                    "message" => "Excel import completed",
                    "total_rows" => $stats['total_rows'],
                    "inserted" => count($brandsToInsert),
                    "skipped_null" => $stats['skipped_null'],
                    "skipped_invalid" => $stats['skipped_invalid'],
                    "skipped_duplicate_file" => $stats['skipped_duplicate_file'],
                    "skipped_duplicate_db" => $skippedDuplicateDb,
                    "row_errors" => $rowErrors
                ];
                echo json_encode($response);
                $logger->logRequestAndResponse(["file" => $fileName], $response);
                break;
            } catch (Exception $e) {
                http_response_code(500);
                $error = ["error" => "Failed to read Excel file"];
                echo json_encode($error);
                $logger->log('Excel import error: ' . $e->getMessage(), 'api', $module, $username);
                $logger->logRequestAndResponse(["file" => $fileName], $error);
                break;
            }
        }

        if (!isset($input['brand']) || empty(trim($input['brand']))) {
            http_response_code(400);
            $error = ["error" => "Brand name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $brand = trim($input['brand']);

        if (!preg_match($regExp, $brand)) {
            http_response_code(400);
            $error = ["error" => "Brand name can only contain letters and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // check duplicate brand
        $existingBrand = $brandObj->isDuplicateBrand($brand, $module, $username);
        if ($existingBrand) {
            http_response_code(409);
            $error = ["error" => "Brand name already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $brandObj->insertAssetBrand($brand, $username, $module, $username);
        if ($result) {
            http_response_code(201);
            $response = ["message" => "Asset Brand created successfully", "id" => $result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to create Asset Brand"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }

        break;

    case 'PUT':
        $logger->log("PUT request received");
        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Asset Brand ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Asset Brand ID must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        if (!isset($input['brand']) || empty(trim($input['brand']))) {
            http_response_code(400);
            $error = ["error" => "Asset Brand name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($regExp, trim($input['brand']))) {
            http_response_code(400);
            $error = ["error" => "Asset Brand name can only contain letters and spaces"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $id = intval($_GET['id']);
        $brand = trim($input['brand']);

        // check duplicate brand
        $existingBrand = $brandObj->isDuplicateBrandForUpdate($id, $brand, $module, $username);
        if ($existingBrand) {
            http_response_code(409);
            $error = ["error" => "Brand name already exists"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }



        $result = $brandObj->updateAssetBrand($id, $brand, $username, $module, $username);
        if ($result) {
            http_response_code(200);
            $response = ["message" => "Asset Brand updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update Asset Brand"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }

        break;

    case 'DELETE':
        $logger->log("DELETE request received");
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Asset Brand ID must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);

        $result = $brandObj->deleteAssetBrand($id, $module, $username);

        if ($result) {
            http_response_code(200);
            $response = ["message" => "Asset Brand deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete Asset Brand"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        break;
}
