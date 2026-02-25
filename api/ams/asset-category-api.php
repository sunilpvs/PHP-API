<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	http_response_code(200);
	exit;
}

require_once __DIR__ . '../../../classes/ams/AssetCategory.php';
require_once __DIR__ . '../../../classes/ams/AssetTypes.php';
require_once __DIR__ . '../../../classes/ams/AssignmentTypes.php';
require_once __DIR__ . '../../../classes/authentication/middle.php';
require_once __DIR__ . '../../../classes/Logger.php';
require_once __DIR__ . '../../../classes/authentication/LoginUser.php';
require_once __DIR__ . '../../../classes/utils/ExcelImportHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use \PhpOffice\PhpSpreadsheet\IOFactory;

// Validate login and authenticate JWT
authenticateJWT();

// Reading app.ini configuration file
$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);
$regExp = '/^[a-zA-Z0-9_\-\/\s]+$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$assetCategoryObj = new AssetCategory();
$assetTypeObj = new AssetTypes();
$assignmentTypeObj = new AssignmentTypes();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
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
			$data = $assetCategoryObj->getAssetCategoriesCombo($module, $username);
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
				$categoryColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-category');
				$assetTypeColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-type');
				$assignmentTypeColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'assignment-type');

				if ($categoryColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Category column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($assetTypeColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Type column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($assignmentTypeColumn === null) {
					http_response_code(400);
					$error = ["error" => "Assignment Type column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				$categoryAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $categoryColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Category is empty',
					'invalid_reason' => 'Asset Category can only contain letters, numbers, underscores, hyphens, and slashes',
					'duplicate_file_reason' => 'Duplicate asset category in file',
				]);

				$rowErrors = $categoryAnalysis['errors'];
				$categoryStats = $categoryAnalysis['stats'];
				$validCategoryRows = $categoryAnalysis['valid_rows'];

				$assetTypeAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $assetTypeColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Type is empty',
					'invalid_reason' => 'Asset Type can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				$assignmentTypeAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $assignmentTypeColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Assignment Type is empty',
					'invalid_reason' => 'Assignment Type can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				foreach ($assetTypeAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				foreach ($assignmentTypeAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				$categoryRowMap = [];
				foreach ($validCategoryRows as $row) {
					$categoryRowMap[$row['row']] = $row;
				}

				$assetTypeRowMap = [];
				foreach ($assetTypeAnalysis['valid_rows'] as $row) {
					$assetTypeRowMap[$row['row']] = $row;
				}

				$assignmentTypeRowMap = [];
				foreach ($assignmentTypeAnalysis['valid_rows'] as $row) {
					$assignmentTypeRowMap[$row['row']] = $row;
				}

				$combinedValidRows = [];
				foreach ($categoryRowMap as $rowIndex => $categoryRow) {
					if (isset($assetTypeRowMap[$rowIndex]) && isset($assignmentTypeRowMap[$rowIndex])) {
						$combinedValidRows[] = [
							'row' => $rowIndex,
							'asset_category' => $categoryRow['value'],
							'asset_category_normalized' => $categoryRow['normalized'],
							'asset_type' => $assetTypeRowMap[$rowIndex]['value'],
							'asset_type_normalized' => $assetTypeRowMap[$rowIndex]['normalized'],
							'assignment_type' => $assignmentTypeRowMap[$rowIndex]['value'],
							'assignment_type_normalized' => $assignmentTypeRowMap[$rowIndex]['normalized'],
						];
					}
				}

				$uniqueAssetTypeNames = array_values(array_unique(array_map(function ($row) {
					return $row['asset_type_normalized'];
				}, $combinedValidRows)));

				$uniqueAssignmentTypeNames = array_values(array_unique(array_map(function ($row) {
					return $row['assignment_type_normalized'];
				}, $combinedValidRows)));

				$assetTypeIdMap = $assetTypeObj->getAssetTypeIdsByNames($uniqueAssetTypeNames, $module, $username);
				$assignmentTypeIdMap = $assignmentTypeObj->getAssignmentTypeIdsByNames($uniqueAssignmentTypeNames, $module, $username);

				$skippedInvalidAssetType = 0;
				$skippedInvalidAssignmentType = 0;
				$rowsWithResolvedIds = [];
				foreach ($combinedValidRows as $row) {
					if (!isset($assetTypeIdMap[$row['asset_type_normalized']])) {
						$skippedInvalidAssetType++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_category'] . ' (Asset Type: ' . $row['asset_type'] . ')',
							'reason' => 'Asset Type does not exist',
						];
						continue;
					}

					if (!isset($assignmentTypeIdMap[$row['assignment_type_normalized']])) {
						$skippedInvalidAssignmentType++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_category'] . ' (Assignment Type: ' . $row['assignment_type'] . ')',
							'reason' => 'Assignment Type does not exist',
						];
						continue;
					}

					$rowsWithResolvedIds[] = [
						'row' => $row['row'],
						'asset_category' => $row['asset_category'],
						'asset_category_normalized' => $row['asset_category_normalized'],
						'asset_type_id' => $assetTypeIdMap[$row['asset_type_normalized']],
						'assignment_type_id' => $assignmentTypeIdMap[$row['assignment_type_normalized']],
						'asset_type' => $row['asset_type'],
						'assignment_type' => $row['assignment_type'],
					];
				}

				$categoriesLower = array_map(function ($row) {
					return $row['asset_category_normalized'];
				}, $rowsWithResolvedIds);

				$existingLower = $assetCategoryObj->getExistingAssetCategoriesByNames($categoriesLower, $module, $username);
				$existingSet = array_fill_keys($existingLower, true);

				$categoriesToInsert = [];
				$skippedDuplicateDb = 0;
				foreach ($rowsWithResolvedIds as $row) {
					if (isset($existingSet[$row['asset_category_normalized']])) {
						$skippedDuplicateDb++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_category'] . ' (Asset Type: ' . $row['asset_type'] . ', Assignment Type: ' . $row['assignment_type'] . ')',
							'reason' => 'Asset Category already exists',
						];
						continue;
					}

					$categoriesToInsert[] = [
						'asset_category' => $row['asset_category'],
						'asset_type_id' => $row['asset_type_id'],
						'assignment_type_id' => $row['assignment_type_id'],
					];
				}

				$rowErrors = ExcelImportHelper::sortRowErrors($rowErrors);

				if (!empty($categoriesToInsert)) {
					$inserted = $assetCategoryObj->insertBatchAssetCategoriesFromExcel($categoriesToInsert, $username);
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
					"total_rows" => $categoryStats['total_rows'],
					"inserted" => count($categoriesToInsert),
					"skipped_null" => $categoryStats['skipped_null'] + $assetTypeAnalysis['stats']['skipped_null'] + $assignmentTypeAnalysis['stats']['skipped_null'],
					"skipped_invalid" => $categoryStats['skipped_invalid'] + $assetTypeAnalysis['stats']['skipped_invalid'] + $assignmentTypeAnalysis['stats']['skipped_invalid'],
					"skipped_duplicate_file" => $categoryStats['skipped_duplicate_file'],
					"skipped_invalid_asset_type" => $skippedInvalidAssetType,
					"skipped_invalid_assignment_type" => $skippedInvalidAssignmentType,
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

		if (!isset($input['asset_type_id']) || !is_numeric($input['asset_type_id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Type ID is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['assignment_type_id']) || !is_numeric($input['assignment_type_id'])) {
			http_response_code(400);
			$error = ["error" => "Assignment Type ID is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$assetCategory = trim($input['asset_category']);
		$assetTypeId = intval($input['asset_type_id']);
		$assignmentTypeId = intval($input['assignment_type_id']);

		if (!preg_match($regExp, $assetCategory)) {
			http_response_code(400);
			$error = ["error" => "Asset Category can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$existingCategory = $assetCategoryObj->isDuplicateAssetCategory($assetCategory, $module, $username);
		if ($existingCategory) {
			http_response_code(409);
			$error = ["error" => "Asset Category already exists"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetCategoryObj->insertAssetCategory($assetCategory, $assetTypeId, $assignmentTypeId, $username, $module, $username);
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

		if (!isset($input['asset_type_id']) || !is_numeric($input['asset_type_id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Type ID is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['assignment_type_id']) || !is_numeric($input['assignment_type_id'])) {
			http_response_code(400);
			$error = ["error" => "Assignment Type ID is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($regExp, trim($input['asset_category']))) {
			http_response_code(400);
			$error = ["error" => "Asset Category name can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$id = intval($_GET['id']);
		$assetCategory = trim($input['asset_category']);
		$assetTypeId = intval($input['asset_type_id']);
		$assignmentTypeId = intval($input['assignment_type_id']);

		$existingCategory = $assetCategoryObj->isDuplicateAssetCategoryForUpdate($id, $assetCategory, $module, $username);
		if ($existingCategory) {
			http_response_code(409);
			$error = ["error" => "Asset Category already exists"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetCategoryObj->updateAssetCategory($id, $assetCategory, $assetTypeId, $assignmentTypeId, $username, $module, $username);
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
