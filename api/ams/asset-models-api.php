<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	http_response_code(200);
	exit;
}

require_once __DIR__ . '../../../classes/ams/AssetModels.php';
require_once __DIR__ . '../../../classes/ams/AssetCategory.php';
require_once __DIR__ . '../../../classes/ams/AssetBrands.php';
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
$regExp = '/^[a-zA-Z0-9\s]+$/';
$lookupRegExp = '/^[a-zA-Z0-9_\-\/\s]+$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$assetModelObj = new AssetModels();
$assetCategoryObj = new AssetCategory();
$assetBrandObj = new AssetBrands();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
$module = 'Admin';

switch ($method) {
	case 'GET':
		$logger->log("GET request received");

		if (isset($_GET['id'])) {
			$id = intval($_GET['id']);
			$data = $assetModelObj->getAssetModelById($id, $module, $username);
			$status = $data ? 200 : 404;
			$response = $data ?: ["error" => "Asset Model not found"];
			http_response_code($status);
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
			break;
		}

		if (isset($_GET['type']) && $_GET['type'] === 'combo') {
			$fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'asset_model'];
			$fields = array_map('trim', $fields);
			$data = $assetModelObj->getAssetModelsCombo($module, $username);
			http_response_code(200);
			echo json_encode($data);
			$logger->logRequestAndResponse($_GET, $data);
			break;
		}

		$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
		$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
		$offset = ($page - 1) * $limit;
		$data = $assetModelObj->getPaginatedAssetModels($limit, $offset, $module, $username);
		$total = $assetModelObj->getAssetModelCount($module, $username);

		$response = [
			'total' => $total,
			'page' => $page,
			'limit' => $limit,
			'asset_models' => $data,
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
				$modelColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-model');
				$configColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'config');
				$categoryColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-category');
				$brandColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-brand');

				if ($modelColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Model column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($configColumn === null) {
					http_response_code(400);
					$error = ["error" => "Config column not found in header"];
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

				if ($brandColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Brand column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				$modelAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $modelColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Model is empty',
					'invalid_reason' => 'Asset Model can only contain letters, numbers and spaces',
					'duplicate_file_reason' => '', // Don't check duplicates by name alone - composite key checked later
				]);

				$rowErrors = $modelAnalysis['errors'];
				$modelStats = $modelAnalysis['stats'];
				$validModelRows = $modelAnalysis['valid_rows'];

				$configAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $configColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Config is empty',
					'invalid_reason' => 'Config can only contain letters, numbers and spaces',
					'duplicate_file_reason' => '',
				]);

				$validConfigRows = $configAnalysis['valid_rows'];
				foreach ($configAnalysis['errors'] as $row => $errors) {
					foreach ($errors as $error) {
						$rowErrors[$row][] = $error;
					}
				}

				$categoryAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $categoryColumn, [
					'regex' => $lookupRegExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Category is empty',
					'invalid_reason' => 'Asset Category can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				$validCategoryRows = $categoryAnalysis['valid_rows'];
				foreach ($categoryAnalysis['errors'] as $row => $errors) {
					foreach ($errors as $error) {
						$rowErrors[$row][] = $error;
					}
				}

				$brandAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $brandColumn, [
					'regex' => $lookupRegExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Brand is empty',
					'invalid_reason' => 'Asset Brand can only contain letters, numbers, spaces, underscores, hyphens, and slashes',
					'duplicate_file_reason' => '',
				]);

				$validBrandRows = $brandAnalysis['valid_rows'];
				foreach ($brandAnalysis['errors'] as $row => $errors) {
					foreach ($errors as $error) {
						$rowErrors[$row][] = $error;
					}
				}

				// Sort and format errors for response

				$modelRowMap = [];
				foreach ($validModelRows as $row) {
					$modelRowMap[$row['row']] = $row;
				}

				$configRowMap = [];
				foreach ($validConfigRows as $row) {
					$configRowMap[$row['row']] = $row;
				}

				$categoryRowMap = [];
				foreach ($validCategoryRows as $row) {
					$categoryRowMap[$row['row']] = $row;
				}

				$brandRowMap = [];
				foreach ($validBrandRows as $row) {
					$brandRowMap[$row['row']] = $row;
				}

				$combinedValidRows = [];
				foreach ($modelRowMap as $rowIndex => $modelRow) {
					if (isset($configRowMap[$rowIndex]) && isset($categoryRowMap[$rowIndex]) && isset($brandRowMap[$rowIndex])) {
						$combinedValidRows[] = [
							'row' => $rowIndex,
							'asset_model' => $modelRow['value'],
							'asset_model_normalized' => $modelRow['normalized'],
							'config' => $configRowMap[$rowIndex]['value'],
							'asset_category' => $categoryRowMap[$rowIndex]['value'],
							'asset_category_normalized' => $categoryRowMap[$rowIndex]['normalized'],
							'asset_brand' => $brandRowMap[$rowIndex]['value'],
							'asset_brand_normalized' => $brandRowMap[$rowIndex]['normalized'],
						];
					}
				}

				$uniqueCategoryNames = array_values(array_unique(array_map(function ($row) {
					return $row['asset_category_normalized'];
				}, $combinedValidRows)));

				$uniqueBrandNames = array_values(array_unique(array_map(function ($row) {
					return $row['asset_brand_normalized'];
				}, $combinedValidRows)));

				$categoryIdMap = $assetCategoryObj->getAssetCategoryIdsByNames($uniqueCategoryNames, $module, $username);
				$brandIdMap = $assetBrandObj->getBrandIdsByNames($uniqueBrandNames, $module, $username);

				$skippedInvalidCategory = 0;
				$skippedInvalidBrand = 0;
				$rowsWithResolvedIds = [];
				foreach ($combinedValidRows as $row) {
					if (!isset($categoryIdMap[$row['asset_category_normalized']])) {
						$skippedInvalidCategory++;
						$rowErrors[$row['row']][] = [
							'row' => $row['row'],
							'value' => $row['asset_model'] . ' (Category: ' . $row['asset_category'] . ')',
							'reason' => 'Asset Category does not exist',
						];
						continue;
					}

					if (!isset($brandIdMap[$row['asset_brand_normalized']])) {
						$skippedInvalidBrand++;
						$rowErrors[$row['row']][] = [
							'row' => $row['row'],
							'value' => $row['asset_model'] . ' (Brand: ' . $row['asset_brand'] . ')',
							'reason' => 'Asset Brand does not exist',
						];
						continue;
					}

					$rowsWithResolvedIds[] = [
						'row' => $row['row'],
						'asset_model' => $row['asset_model'],
						'asset_model_normalized' => $row['asset_model_normalized'],
						'config' => $row['config'],
						'asset_category_id' => $categoryIdMap[$row['asset_category_normalized']],
						'brand_id' => $brandIdMap[$row['asset_brand_normalized']],
						'asset_category' => $row['asset_category'],
						'asset_brand' => $row['asset_brand'],
					];
				}

				// Check for duplicate composite keys within the Excel file itself
				$seenCompositeKeys = [];
				$skippedDuplicateFile = 0;
				$validRowsNoDuplicates = [];
				foreach ($rowsWithResolvedIds as $row) {
					$compositeKey = strtolower(trim($row['asset_model'])) . '|' . $row['asset_category_id'] . '|' . $row['brand_id'];
					if (isset($seenCompositeKeys[$compositeKey])) {
						$skippedDuplicateFile++;
						$rowErrors[$row['row']][] = [
							'row' => $row['row'],
							'value' => $row['asset_model'] . ' (Category: ' . $row['asset_category'] . ', Brand: ' . $row['asset_brand'] . ')',
							'reason' => 'Duplicate asset model with same category and brand in file',
						];
						continue;
					}
					$seenCompositeKeys[$compositeKey] = true;
					$validRowsNoDuplicates[] = $row;
				}

				// Check for duplicates using composite key (model + category + brand)
				$modelsDataForDupCheck = array_map(function ($row) {
					return [
						'asset_model' => $row['asset_model'],
						'asset_category_id' => $row['asset_category_id'],
						'brand_id' => $row['brand_id'],
					];
				}, $validRowsNoDuplicates);

				$modelsToInsert = [];
				$skippedDuplicateDb = 0;

				foreach ($validRowsNoDuplicates as $row) {
					$isDuplicate = $assetModelObj->isDuplicateAssetModel(
						$row['asset_model'],
						$row['asset_category_id'],
						$row['brand_id'],
						$module,
						$username
					);

					if ($isDuplicate) {
						$skippedDuplicateDb++;
						$rowErrors[$row['row']][] = [
							'row' => $row['row'],
							'value' => $row['asset_model'] . ' (Category: ' . $row['asset_category'] . ', Brand: ' . $row['asset_brand'] . ')',
							'reason' => 'Asset Model with same category and brand already exists in DB',
						];
						continue;
					}

					$modelsToInsert[] = [
						'asset_model' => $row['asset_model'],
						'config' => $row['config'],
						'asset_category_id' => $row['asset_category_id'],
						'brand_id' => $row['brand_id'],
					];
				}

				$rowErrors = ExcelImportHelper::sortRowErrors($rowErrors);


				if (!empty($modelsToInsert)) {
					$inserted = $assetModelObj->insertBatchAssetModelsFromExcel($modelsToInsert, $username);
					if (!$inserted) {
						http_response_code(500);
						$error = ["error" => "Failed to import asset models from Excel"];
						echo json_encode($error);
						$logger->logRequestAndResponse(["file" => $fileName], $error);
						break;
					}
				}

				http_response_code(200);
				$response = [
					"message" => "Excel import completed",
					"total_rows" => $modelStats['total_rows'],
					"inserted" => count($modelsToInsert),
					"skipped_null" => $modelStats['skipped_null'] + $configAnalysis['stats']['skipped_null'] + $categoryAnalysis['stats']['skipped_null'] + $brandAnalysis['stats']['skipped_null'],
					"skipped_invalid" => $modelStats['skipped_invalid'] + $configAnalysis['stats']['skipped_invalid'] + $categoryAnalysis['stats']['skipped_invalid'] + $brandAnalysis['stats']['skipped_invalid'],
					"skipped_duplicate_file" => $skippedDuplicateFile,
					"skipped_invalid_category" => $skippedInvalidCategory,
					"skipped_invalid_brand" => $skippedInvalidBrand,
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

		if (!isset($input['asset_model']) || empty(trim($input['asset_model']))) {
			http_response_code(400);
			$error = ["error" => "Asset Model name is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['config']) || empty(trim($input['config']))) {
			http_response_code(400);
			$error = ["error" => "Config is required"];
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

		if (!isset($input['brand_id']) || !is_numeric($input['brand_id'])) {
			http_response_code(400);
			$error = ["error" => "Brand ID is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$assetModel = trim($input['asset_model']);
		$configValue = trim($input['config']);
		$assetCategoryId = intval($input['asset_category_id']);
		$brandId = intval($input['brand_id']);

		if (!preg_match($regExp, $assetModel)) {
			http_response_code(400);
			$error = ["error" => "Asset Model can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($regExp, $configValue)) {
			http_response_code(400);
			$error = ["error" => "Config can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$existingModel = $assetModelObj->isDuplicateAssetModel($assetModel, $assetCategoryId, $brandId, $module, $username);
		if ($existingModel) {
			http_response_code(409);
			$error = ["error" => "Asset Model with this category and brand already exists"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetModelObj->insertAssetModel($assetModel, $configValue, $assetCategoryId, $brandId, $username, $module, $username);
		if ($result) {
			http_response_code(201);
			$response = ["message" => "Asset Model created successfully", "id" => $result];
			echo json_encode($response);
			$logger->logRequestAndResponse($input, $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to create Asset Model"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
		}

		break;

	case 'PUT':
		$logger->log("PUT request received");
		if (!isset($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Model ID is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Model ID must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		if (!isset($input['asset_model']) || empty(trim($input['asset_model']))) {
			http_response_code(400);
			$error = ["error" => "Asset Model name is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['config']) || empty(trim($input['config']))) {
			http_response_code(400);
			$error = ["error" => "Config is required"];
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

		if (!isset($input['brand_id']) || !is_numeric($input['brand_id'])) {
			http_response_code(400);
			$error = ["error" => "Brand ID is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($regExp, trim($input['asset_model']))) {
			http_response_code(400);
			$error = ["error" => "Asset Model name can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($regExp, trim($input['config']))) {
			http_response_code(400);
			$error = ["error" => "Config can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$id = intval($_GET['id']);
		$assetModel = trim($input['asset_model']);
		$configValue = trim($input['config']);
		$assetCategoryId = intval($input['asset_category_id']);
		$brandId = intval($input['brand_id']);

		$existingModel = $assetModelObj->isDuplicateAssetModelForUpdate($id, $assetModel, $assetCategoryId, $brandId, $module, $username);
		if ($existingModel) {
			http_response_code(409);
			$error = ["error" => "Asset Model with this category and brand already exists"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetModelObj->updateAssetModel($id, $assetModel, $configValue, $assetCategoryId, $brandId, $username, $module, $username);
		if ($result) {
			http_response_code(200);
			$response = ["message" => "Asset Model updated successfully"];
			echo json_encode($response);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to update Asset Model"];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
		}

		break;

	case 'DELETE':
		$logger->log("DELETE request received");
		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Model ID must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		$id = intval($_GET['id']);

		$result = $assetModelObj->deleteAssetModel($id, $module, $username);

		if ($result) {
			http_response_code(200);
			$response = ["message" => "Asset Model deleted successfully"];
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to delete Asset Model"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
		}
		break;

	default:
		http_response_code(405);
		echo json_encode(['error' => 'Method Not Allowed']);
		break;
}
