<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '../../../classes/ams/AssetCategory.php';
require_once __DIR__ . '../../../classes/ams/AssetFamily.php';
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
$regExp = '/^[a-zA-Z0-9_\-\/\s]+$/';
//Front End authorization as Trusted Hosts.

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$assetCategoryObj = new AssetCategory();
$assetFamilyObj = new AssetFamily();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';

// $username = 'guest';
$module = 'Admin';

switch ($method) {
    case 'GET':
        $logger->log("GET request received");

        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $data = $assetCategoryObj->getAssetCategoryById($id, $module, $username);
            $status = $data ? 200 : 404;
            $response = $data ?: ["error" => "Asset Category not found"];
            http_response_code($status);
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'combo') {
            $fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'asset_category'];
            $fields = array_map('trim', $fields);
            $data = $assetCategoryObj->getAssetCategoriesCombo($module, $username, $fields);
            http_response_code(200);
            echo json_encode($data);
            $logger->logRequestAndResponse($_GET, $data);
            break;
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        $data = $assetCategoryObj->getPaginatedAssetCategories($limit, $offset, $module, $username);
        $total = $assetCategoryObj->getAssetCategoryCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'asset_categories' => $data,
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
                $assetCategoryColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-category');
                $assetFamilyColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-family');

                if ($assetCategoryColumn === null) {
                    http_response_code(400);
                    $error = ["error" => "Asset Category column not found in header"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(["file" => $fileName], $error);
                    break;
                }

                if ($assetFamilyColumn === null) {
                    http_response_code(400);
                    $error = ["error" => "Asset Family column not found in header"];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(["file" => $fileName], $error);
                    break;
                }

                // Analyze asset_category column
                $analysis = ExcelImportHelper::analyzeColumnValues($rows, $assetCategoryColumn, [
                    'regex' => $regExp,
                    'null_values' => ['', 'null'],
                    'null_reason' => 'Asset Category is empty',
                    'invalid_reason' => 'Asset Category can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
                    'duplicate_file_reason' => '',
                ]);

                $rowErrors = $analysis['errors'];
                $stats = $analysis['stats'];
                $validRows = $analysis['valid_rows'];

                // Analyze asset_family column for the same rows
                $familyAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $assetFamilyColumn, [
                    'regex' => $regExp,
                    'null_values' => ['', 'null'],
                    'null_reason' => 'Asset Family is empty',
                    'invalid_reason' => 'Asset Family can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
                    'duplicate_file_reason' => '', // We don't track duplicates for family column
                ]);

                // Merge errors from both columns
                foreach ($familyAnalysis['errors'] as $error) {
                    if ($error['reason'] !== '') {
                        $rowErrors[] = $error;
                    }
                }

                // Build a map of row index to valid data
                $validRowMap = [];
                foreach ($validRows as $row) {
                    $validRowMap[$row['row']] = $row;
                }

                // Build a map of row index to family data
                $familyRowMap = [];
                foreach ($familyAnalysis['valid_rows'] as $row) {
                    $familyRowMap[$row['row']] = $row;
                }

                // Combine asset category and family for rows that are valid in both columns
                $combinedValidRows = [];
                foreach ($validRowMap as $rowIndex => $assetCategoryRow) {
                    if (isset($familyRowMap[$rowIndex])) {
                        $combinedValidRows[] = [
                            'row' => $rowIndex,
                            'asset_category' => $assetCategoryRow['value'],
                            'asset_category_normalized' => $assetCategoryRow['normalized'],
                            'asset_family' => $familyRowMap[$rowIndex]['value'],
                            'asset_family_normalized' => $familyRowMap[$rowIndex]['normalized'],
                        ];
                    }
                }

                $skippedDuplicateFileCombination = 0;
                $seenFileCombinationSet = [];
                $uniqueCombinedRows = [];
                foreach ($combinedValidRows as $row) {
                    $fileCombinationKey = $row['asset_category_normalized'] . '|' . $row['asset_family_normalized'];
                    if (isset($seenFileCombinationSet[$fileCombinationKey])) {
                        $skippedDuplicateFileCombination++;
                        $rowErrors[] = [
                            'row' => $row['row'],
                            'value' => $row['asset_category'] . ' (Family: ' . $row['asset_family'] . ')',
                            'reason' => 'Duplicate asset category and family combination in file',
                        ];
                        continue;
                    }

                    $seenFileCombinationSet[$fileCombinationKey] = true;
                    $uniqueCombinedRows[] = $row;
                }

                // Get unique family names to resolve IDs
                $uniqueFamilyNames = array_unique(array_map(function ($row) {
                    return $row['asset_family_normalized'];
                }, $uniqueCombinedRows));

                $familyIdMap = $assetFamilyObj->getFamilyIdsByNames(array_values($uniqueFamilyNames), $module, $username);

                // Check which families don't exist and mark as errors
                $skippedInvalidFamily = 0;
                $rowsWithFamilyIds = [];
                foreach ($uniqueCombinedRows as $row) {
                    $familyNormalized = $row['asset_family_normalized'];
                    if (!isset($familyIdMap[$familyNormalized])) {
                        $skippedInvalidFamily++;
                        $rowErrors[] = [
                            'row' => $row['row'],
                            'value' => $row['asset_category'] . ' (Family: ' . $row['asset_family'] . ')',
                            'reason' => 'Asset Family does not exist',
                        ];
                        continue;
                    }
                    
                    $rowsWithFamilyIds[] = [
                        'row' => $row['row'],
                        'asset_category' => $row['asset_category'],
                        'asset_category_normalized' => $row['asset_category_normalized'],
                        'asset_family' => $row['asset_family'],
                        'family_id' => $familyIdMap[$familyNormalized],
                    ];
                }

                // Check for duplicates in database (asset_category + family_id is unique)
                $dbCheckCombinations = [];
                $seenDbCheckCombinations = [];
                foreach ($rowsWithFamilyIds as $row) {
                    $dbCheckKey = $row['asset_category_normalized'] . '|' . $row['family_id'];
                    if (isset($seenDbCheckCombinations[$dbCheckKey])) {
                        continue;
                    }
                    $seenDbCheckCombinations[$dbCheckKey] = true;
                    $dbCheckCombinations[] = [
                        'asset_category_normalized' => $row['asset_category_normalized'],
                        'family_id' => $row['family_id'],
                    ];
                }

                $existingCombinationKeys = $assetCategoryObj->getExistingAssetCategoryCombinations($dbCheckCombinations, $module, $username);
                $existingSet = array_fill_keys($existingCombinationKeys, true);

                $assetCategoriesToInsert = [];
                $skippedDuplicateDb = 0;
                foreach ($rowsWithFamilyIds as $row) {
                    $dbCombinationKey = $row['asset_category_normalized'] . '|' . $row['family_id'];
                    if (isset($existingSet[$dbCombinationKey])) {
                        $skippedDuplicateDb++;
                        $rowErrors[] = [
                            'row' => $row['row'],
                            'value' => $row['asset_category'] . ' (Family: ' . $row['asset_family'] . ')',
                            'reason' => 'Asset Category already exists',
                        ];
                        continue;
                    }
                    $assetCategoriesToInsert[] = [
                        'asset_category' => $row['asset_category'],
                        'asset_category_normalized' => $row['asset_category_normalized'],
                        'family_id' => $row['family_id'],
                    ];
                }

                $rowErrors = ExcelImportHelper::sortRowErrors($rowErrors);

                if (!empty($assetCategoriesToInsert)) {
                    $inserted = $assetCategoryObj->insertBatchAssetCategoriesFromExcel($assetCategoriesToInsert, $username);
                    if (!$inserted) {
                        http_response_code(500);
                        $error = ["error" => "Failed to import asset categories from Excel"];
                        echo json_encode($error);
                        $logger->logRequestAndResponse(["file" => $fileName], $error);
                        break;
                    }
                }

                http_response_code(200);
                $response = [
                    "message" => "Excel import completed",
                    "total_rows" => $stats['total_rows'],
                    "inserted" => count($assetCategoriesToInsert),
                    "skipped_null" => $stats['skipped_null'] + $groupAnalysis['stats']['skipped_null'],
                    "skipped_invalid" => $stats['skipped_invalid'] + $groupAnalysis['stats']['skipped_invalid'],
                    "skipped_duplicate_file" => $skippedDuplicateFileCombination,
                    "skipped_invalid_family" => $skippedInvalidFamily,
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

        if (!isset($input['asset_category']) || empty(trim($input['asset_category']))) {
            http_response_code(400);
            $error = ["error" => "Asset Category name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if(!isset($input['family_id']) || !is_numeric($input['family_id'])) {
            http_response_code(400);
            $error = ["error" => "Family ID is required and must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }


        $assetCategory = trim($input['asset_category']);
        $familyId = intval($input['family_id']);

        if (!preg_match($regExp, $assetCategory)) {
            http_response_code(400);
            $error = ["error" => "Asset Category can only contain letters, numbers, spaces, underscores, hyphens, and slashes"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        // check duplicate asset category
        $existingAssetCategory = $assetCategoryObj->isDuplicateAssetCategory($assetCategory, $familyId, $module, $username);
        if ($existingAssetCategory) {
            http_response_code(409);
            $error = ["error" => "Asset Category already exists in the same family"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $assetCategoryObj->insertAssetCategory($assetCategory, $familyId, $username, $module, $username);
        if ($result) {
            http_response_code(201);
            $response = ["message" => "Asset Category created successfully", "id" => $result];
            echo json_encode($response);
            $logger->logRequestAndResponse($input, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to create Asset Category"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }

        break;

    case 'PUT':
        $logger->log("PUT request received");
        if (!isset($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Asset Category ID is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Asset Category ID must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        if (!isset($input['asset_category']) || empty(trim($input['asset_category']))) {
            http_response_code(400);
            $error = ["error" => "Asset Category name is required"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if(!isset($input['family_id']) || !is_numeric($input['family_id'])) {
            http_response_code(400);
            $error = ["error" => "Family ID is required and must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($regExp, trim($input['asset_category']))) {
            http_response_code(400);
            $error = ["error" => "Asset Category name can only contain letters, numbers, spaces, underscores, hyphens, and slashes"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $id = intval($_GET['id']);
        $familyId = intval($input['family_id']);
        $assetCategory = trim($input['asset_category']);

        // check duplicate asset category
        $existingAssetCategory = $assetCategoryObj->isDuplicateAssetCategoryForUpdate($id, $assetCategory, $familyId, $module, $username);
        if ($existingAssetCategory) {
            http_response_code(409);
            $error = ["error" => "Asset Category already exists in the same family"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        $result = $assetCategoryObj->updateAssetCategory($id, $assetCategory, $familyId, $username, $module, $username);
        if ($result) {
            http_response_code(200);
            $response = ["message" => "Asset Category updated successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to update Asset Category"];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }

        break;

    case 'DELETE':
        $logger->log("DELETE request received");
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ["error" => "Asset Category ID must be a valid number"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = intval($_GET['id']);

        $result = $assetCategoryObj->deleteAssetCategory($id, $module, $username);

        if ($result) {
            http_response_code(200);
            $response = ["message" => "Asset Category deleted successfully"];
            echo json_encode($response);
            $logger->logRequestAndResponse($_GET, $response);
        } else {
            http_response_code(500);
            $error = ["error" => "Failed to delete Asset Category"];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        break;
}
