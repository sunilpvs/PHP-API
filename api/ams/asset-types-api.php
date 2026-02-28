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
			$data = $assetTypeObj->getAssetTypeById($id, $module, $username);
			$status = $data ? 200 : 404;
			$response = $data ?: ["error" => "Asset Type not found"];
			http_response_code($status);
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
			break;
		}

		if (isset($_GET['type']) && $_GET['type'] === 'combo') {
			$fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'asset_type'];
			$fields = array_map('trim', $fields);
			$data = $assetTypeObj->getAssetTypesCombo($module, $username);
			http_response_code(200);
			echo json_encode($data);
			$logger->logRequestAndResponse($_GET, $data);
			break;
		}

		$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
		$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
		$offset = ($page - 1) * $limit;
		$data = $assetTypeObj->getPaginatedAssetTypes($limit, $offset, $module, $username);
		$total = $assetTypeObj->getAssetTypeCount($module, $username);

		$response = [
			'total' => $total,
			'page' => $page,
			'limit' => $limit,
			'asset_types' => $data,
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
				$typeColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-type');
				$categoryColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-category');
				$assignmentTypeColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'assignment-type');

				if ($typeColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Type column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($categoryColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Category column not found in header"];
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

				$typeAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $typeColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Type is empty',
					'invalid_reason' => 'Asset Type can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				$rowErrors = $typeAnalysis['errors'];
				$typeStats = $typeAnalysis['stats'];

				$categoryAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $categoryColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Category is empty',
					'invalid_reason' => 'Asset Category can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				$assignmentTypeAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $assignmentTypeColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Assignment Type is empty',
					'invalid_reason' => 'Assignment Type can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				foreach ($categoryAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				foreach ($assignmentTypeAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				$typeRowMap = [];
				foreach ($typeAnalysis['valid_rows'] as $row) {
					$typeRowMap[$row['row']] = $row;
				}

				$categoryRowMap = [];
				foreach ($categoryAnalysis['valid_rows'] as $row) {
					$categoryRowMap[$row['row']] = $row;
				}

				$assignmentTypeRowMap = [];
				foreach ($assignmentTypeAnalysis['valid_rows'] as $row) {
					$assignmentTypeRowMap[$row['row']] = $row;
				}

				$combinedValidRows = [];
				foreach ($typeRowMap as $rowIndex => $typeRow) {
					if (isset($categoryRowMap[$rowIndex]) && isset($assignmentTypeRowMap[$rowIndex])) {
						$combinedValidRows[] = [
							'row' => $rowIndex,
							'asset_type' => $typeRow['value'],
							'asset_type_normalized' => $typeRow['normalized'],
							'asset_category' => $categoryRowMap[$rowIndex]['value'],
							'asset_category_normalized' => $categoryRowMap[$rowIndex]['normalized'],
							'assignment_type' => $assignmentTypeRowMap[$rowIndex]['value'],
							'assignment_type_normalized' => $assignmentTypeRowMap[$rowIndex]['normalized'],
						];
					}
				}

				$skippedDuplicateFileCombination = 0;
				$seenFileCombinationSet = [];
				$uniqueCombinedRows = [];
				foreach ($combinedValidRows as $row) {
					$fileCombinationKey = $row['asset_type_normalized'] . '|' . $row['asset_category_normalized'] . '|' . $row['assignment_type_normalized'];
					if (isset($seenFileCombinationSet[$fileCombinationKey])) {
						$skippedDuplicateFileCombination++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_type'] . ' (Asset Category: ' . $row['asset_category'] . ', Assignment Type: ' . $row['assignment_type'] . ')',
							'reason' => 'Duplicate asset type combination in file',
						];
						continue;
					}

					$seenFileCombinationSet[$fileCombinationKey] = true;
					$uniqueCombinedRows[] = $row;
				}

				$uniqueCategoryNames = array_values(array_unique(array_map(function ($row) {
					return $row['asset_category_normalized'];
				}, $uniqueCombinedRows)));

				$uniqueAssignmentTypeNames = array_values(array_unique(array_map(function ($row) {
					return $row['assignment_type_normalized'];
				}, $uniqueCombinedRows)));

				$assetCategoryIdMap = $assetCategoryObj->getAssetCategoryIdsByNames($uniqueCategoryNames, $module, $username);
				$assignmentTypeIdMap = $assignmentTypeObj->getAssignmentTypeIdsByNames($uniqueAssignmentTypeNames, $module, $username);

				$skippedInvalidAssetCategory = 0;
				$skippedInvalidAssignmentType = 0;
				$rowsWithResolvedIds = [];
				foreach ($uniqueCombinedRows as $row) {
					if (!isset($assetCategoryIdMap[$row['asset_category_normalized']])) {
						$skippedInvalidAssetCategory++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_type'] . ' (Asset Category: ' . $row['asset_category'] . ')',
							'reason' => 'Asset Category does not exist',
						];
						continue;
					}

					if (!isset($assignmentTypeIdMap[$row['assignment_type_normalized']])) {
						$skippedInvalidAssignmentType++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_type'] . ' (Assignment Type: ' . $row['assignment_type'] . ')',
							'reason' => 'Assignment Type does not exist',
						];
						continue;
					}

					$rowsWithResolvedIds[] = [
						'row' => $row['row'],
						'asset_type' => $row['asset_type'],
						'asset_type_normalized' => $row['asset_type_normalized'],
						'asset_category' => $row['asset_category'],
						'asset_category_id' => $assetCategoryIdMap[$row['asset_category_normalized']],
						'assignment_type' => $row['assignment_type'],
						'assignment_type_id' => $assignmentTypeIdMap[$row['assignment_type_normalized']],
					];
				}

				$dbCheckCombinations = [];
				$seenDbCheckCombinations = [];
				foreach ($rowsWithResolvedIds as $row) {
					$dbCheckKey = $row['asset_type_normalized'] . '|' . $row['asset_category_id'] . '|' . $row['assignment_type_id'];
					if (isset($seenDbCheckCombinations[$dbCheckKey])) {
						continue;
					}
					$seenDbCheckCombinations[$dbCheckKey] = true;
					$dbCheckCombinations[] = [
						'asset_type_normalized' => $row['asset_type_normalized'],
						'asset_category_id' => $row['asset_category_id'],
						'assignment_type_id' => $row['assignment_type_id'],
					];
				}

				$existingCombinationKeys = $assetTypeObj->getExistingAssetTypeCombinations($dbCheckCombinations, $module, $username);
				$existingSet = array_fill_keys($existingCombinationKeys, true);

				$typesToInsert = [];
				$skippedDuplicateDb = 0;
				foreach ($rowsWithResolvedIds as $row) {
					$dbCombinationKey = $row['asset_type_normalized'] . '|' . $row['asset_category_id'] . '|' . $row['assignment_type_id'];
					if (isset($existingSet[$dbCombinationKey])) {
						$skippedDuplicateDb++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_type'] . ' (Asset Category: ' . $row['asset_category'] . ', Assignment Type: ' . $row['assignment_type'] . ')',
							'reason' => 'Asset Type already exists with the same Asset Category and Assignment Type',
						];
						continue;
					}

					$typesToInsert[] = [
						'asset_type' => $row['asset_type'],
						'asset_category_id' => $row['asset_category_id'],
						'assignment_type_id' => $row['assignment_type_id'],
					];
				}

				$rowErrors = ExcelImportHelper::sortRowErrors($rowErrors);

				if (!empty($typesToInsert)) {
					$inserted = $assetTypeObj->insertBatchAssetTypesFromExcel($typesToInsert, $username);
					if (!$inserted) {
						http_response_code(500);
						$error = ["error" => "Failed to import asset types from Excel"];
						echo json_encode($error);
						$logger->logRequestAndResponse(["file" => $fileName], $error);
						break;
					}
				}

				http_response_code(200);
				$response = [
					"message" => "Excel import completed",
					"total_rows" => $typeStats['total_rows'],
					"inserted" => count($typesToInsert),
					"skipped_null" => $typeStats['skipped_null'] + $categoryAnalysis['stats']['skipped_null'] + $assignmentTypeAnalysis['stats']['skipped_null'],
					"skipped_invalid" => $typeStats['skipped_invalid'] + $categoryAnalysis['stats']['skipped_invalid'] + $assignmentTypeAnalysis['stats']['skipped_invalid'],
					"skipped_duplicate_file" => $skippedDuplicateFileCombination,
					"skipped_invalid_asset_category" => $skippedInvalidAssetCategory,
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

		if (!isset($input['asset_type']) || empty(trim($input['asset_type']))) {
			http_response_code(400);
			$error = ["error" => "Asset Type name is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_category_id']) || !is_numeric($input['asset_category_id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Category ID is required and must be a valid number"];
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

		$assetType = trim($input['asset_type']);
		$assetCategoryId = intval($input['asset_category_id']);
		$assignmentTypeId = intval($input['assignment_type_id']);

		if (!preg_match($regExp, $assetType)) {
			http_response_code(400);
			$error = ["error" => "Asset Type can only contain letters, numbers and spaces "];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$existingCategory = $assetTypeObj->isDuplicateAssetType($assetType, $assetCategoryId, $assignmentTypeId, $module, $username);
		if ($existingCategory) {
			http_response_code(409);
			$error = ["error" => "Asset Type already exists with the same Asset Category and Assignment Type"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetTypeObj->insertAssetType($assetType, $assetCategoryId, $assignmentTypeId, $username, $module, $username);
		if ($result) {
			http_response_code(201);
			$response = ["message" => "Asset Type created successfully", "id" => $result];
			echo json_encode($response);
			$logger->logRequestAndResponse($input, $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to create Asset Type"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
		}

		break;

	case 'PUT':
		$logger->log("PUT request received");
		if (!isset($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Type ID is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Type ID must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		if (!isset($input['asset_type']) || empty(trim($input['asset_type']))) {
			http_response_code(400);
			$error = ["error" => "Asset Type name is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_category_id']) || !is_numeric($input['asset_category_id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Category ID is required and must be a valid number"];
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

		if (!preg_match($regExp, trim($input['asset_type']))) {
			http_response_code(400);
			$error = ["error" => "Asset Type name can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$id = intval($_GET['id']);
		$assetType = trim($input['asset_type']);
		$assetCategoryId = intval($input['asset_category_id']);
		$assignmentTypeId = intval($input['assignment_type_id']);

		$existingType = $assetTypeObj->isDuplicateAssetTypeForUpdate($id, $assetType, $assetCategoryId, $assignmentTypeId, $module, $username);
		if ($existingType) {
			http_response_code(409);
			$error = ["error" => "Asset Type already exists with the same Asset Category and Assignment Type"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetTypeObj->updateAssetType($id, $assetType, $assetCategoryId, $assignmentTypeId, $username, $module, $username);
		if ($result) {
			http_response_code(200);
			$response = ["message" => "Asset Type updated successfully"];
			echo json_encode($response);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to update Asset Type"];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
		}

		break;

	case 'DELETE':
		$logger->log("DELETE request received");
		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Type ID must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		$id = intval($_GET['id']);

		$result = $assetTypeObj->deleteAssetType($id, $module, $username);

		if ($result) {
			http_response_code(200);
			$response = ["message" => "Asset Type deleted successfully"];
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to delete Asset Type"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
		}
		break;

	default:
		http_response_code(405);
		echo json_encode(['error' => 'Method Not Allowed']);
		break;
}
