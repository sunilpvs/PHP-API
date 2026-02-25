<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
	http_response_code(200);
	exit;
}
require_once __DIR__ . '../../../classes/ams/AssetInfo.php';
require_once __DIR__ . '../../../classes/ams/AssetModels.php';
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
// date in YYYY-MM-DD or DD-MM-YYYY format
$dateRegExp = '/^(\d{4}-\d{2}-\d{2}|\d{2}-\d{2}-\d{4})$/';
$priceRegExp = '/^\d+(\.\d{1,2})?$/';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

$assetInfoObj = new AssetInfo();
$assetModelObj = new AssetModels();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
$module = 'Admin';

switch ($method) {
	case 'GET':
		$logger->log("GET request received");

		if (isset($_GET['id'])) {
			$id = intval($_GET['id']);
			$data = $assetInfoObj->getAssetInfoById($id, $module, $username);
			$status = $data ? 200 : 404;
			$response = $data ?: ["error" => "Asset Info not found"];
			http_response_code($status);
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
			break;
		}

		if (isset($_GET['type']) && $_GET['type'] === 'combo') {
			$fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'asset_serial_number'];
			$fields = array_map('trim', $fields);
			$data = $assetInfoObj->getAssetInfoCombo($module, $username);
			http_response_code(200);
			echo json_encode($data);
			$logger->logRequestAndResponse($_GET, $data);
			break;
		}

		$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
		$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
		$offset = ($page - 1) * $limit;
		$data = $assetInfoObj->getPaginatedAssetInfo($limit, $offset, $module, $username);
		$total = $assetInfoObj->getAssetInfoCount($module, $username);

		$response = [
			'total' => $total,
			'page' => $page,
			'limit' => $limit,
			'asset_info' => $data,
		];

		http_response_code(200);
		echo json_encode($response);
		$logger->logRequestAndResponse($_GET, $response);

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
				$serialColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-serial-number');
				$purchaseDateColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-purchase-date');
				$priceColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-price');
				$warrantyColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-warranty-expiry');
				$modelColumn = ExcelImportHelper::findHeaderColumn($headerRow, 'asset-model');

				if ($serialColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Serial Number column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($purchaseDateColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Purchase Date column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($priceColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Price column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($warrantyColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Warranty Expiry column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				if ($modelColumn === null) {
					http_response_code(400);
					$error = ["error" => "Asset Model column not found in header"];
					echo json_encode($error);
					$logger->logRequestAndResponse(["file" => $fileName], $error);
					break;
				}

				$normalizeExcelDate = function ($value) {
					if ($value instanceof DateTimeInterface) {
						return $value->format('Y-m-d');
					}

					$raw = trim((string)$value);
					if ($raw === '') {
						return $raw;
					}

					if (is_numeric($value)) {
						try {
							$dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
							return $dt->format('Y-m-d');
						} catch (Exception $e) {
							return $raw;
						}
					}

					$formats = [
						'Y-m-d',
						'Y/m/d',
						'm/d/Y',
						'n/j/Y',
						'd/m/Y',
						'j/n/Y',
						'm-d-Y',
						'n-j-Y',
						'd-m-Y',
						'j-n-Y',
					];

					foreach ($formats as $format) {
						$dt = DateTime::createFromFormat($format, $raw);
						$errors = DateTime::getLastErrors();
						if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
							return $dt->format('Y-m-d');
						}
					}

					return $raw;
				};

				foreach ($rows as $index => $row) {
					if ($index === 1) {
						continue;
					}

					if (isset($row[$purchaseDateColumn])) {
						$rows[$index][$purchaseDateColumn] = $normalizeExcelDate($row[$purchaseDateColumn]);
					}

					if (isset($row[$warrantyColumn])) {
						$rows[$index][$warrantyColumn] = $normalizeExcelDate($row[$warrantyColumn]);
					}
				}

				$serialAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $serialColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Serial Number is empty',
					'invalid_reason' => 'Asset Serial Number can only contain letters, numbers, underscores, hyphens, forward slashes and spaces',
					'duplicate_file_reason' => 'Duplicate asset serial number in file',
				]);

				$rowErrors = $serialAnalysis['errors'];
				$serialStats = $serialAnalysis['stats'];
				$validSerialRows = $serialAnalysis['valid_rows'];

				$purchaseDateAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $purchaseDateColumn, [
					'regex' => $dateRegExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Purchase Date is empty',
					'invalid_reason' => 'Asset Purchase Date must be in YYYY-MM-DD format',
					'duplicate_file_reason' => '',
				]);

				$priceAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $priceColumn, [
					'regex' => $priceRegExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Price is empty',
					'invalid_reason' => 'Asset Price must be a valid number',
					'duplicate_file_reason' => '',
				]);

				$warrantyAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $warrantyColumn, [
					'regex' => $dateRegExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Warranty Expiry is empty',
					'invalid_reason' => 'Asset Warranty Expiry must be in YYYY-MM-DD format',
					'duplicate_file_reason' => '',
				]);

				$modelAnalysis = ExcelImportHelper::analyzeColumnValues($rows, $modelColumn, [
					'regex' => $regExp,
					'null_values' => ['', 'null'],
					'null_reason' => 'Asset Model is empty',
					'invalid_reason' => 'Asset Model can only contain letters, numbers, underscores, hyphens, forward slashes and spaces',
					'duplicate_file_reason' => '',
				]);

				foreach ($purchaseDateAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				foreach ($priceAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				foreach ($warrantyAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				foreach ($modelAnalysis['errors'] as $error) {
					if ($error['reason'] !== '') {
						$rowErrors[] = $error;
					}
				}

				$serialRowMap = [];
				foreach ($validSerialRows as $row) {
					$serialRowMap[$row['row']] = $row;
				}

				$purchaseRowMap = [];
				foreach ($purchaseDateAnalysis['valid_rows'] as $row) {
					$purchaseRowMap[$row['row']] = $row;
				}

				$priceRowMap = [];
				foreach ($priceAnalysis['valid_rows'] as $row) {
					$priceRowMap[$row['row']] = $row;
				}

				$warrantyRowMap = [];
				foreach ($warrantyAnalysis['valid_rows'] as $row) {
					$warrantyRowMap[$row['row']] = $row;
				}

				$modelRowMap = [];
				foreach ($modelAnalysis['valid_rows'] as $row) {
					$modelRowMap[$row['row']] = $row;
				}

				$combinedValidRows = [];
				foreach ($serialRowMap as $rowIndex => $serialRow) {
					if (isset($purchaseRowMap[$rowIndex]) && isset($priceRowMap[$rowIndex]) && isset($warrantyRowMap[$rowIndex]) && isset($modelRowMap[$rowIndex])) {
						$combinedValidRows[] = [
							'row' => $rowIndex,
							'asset_serial_number' => $serialRow['value'],
							'asset_serial_number_normalized' => $serialRow['normalized'],
							'asset_purchase_date' => $purchaseRowMap[$rowIndex]['value'],
							'asset_price' => $priceRowMap[$rowIndex]['value'],
							'asset_warranty_expiry' => $warrantyRowMap[$rowIndex]['value'],
							'asset_model' => $modelRowMap[$rowIndex]['value'],
							'asset_model_normalized' => $modelRowMap[$rowIndex]['normalized'],
						];
					}
				}

				$uniqueModelNames = array_values(array_unique(array_map(function ($row) {
					return $row['asset_model_normalized'];
				}, $combinedValidRows)));

				$modelLookup = $assetModelObj->getAssetModelIdsByNames($uniqueModelNames, $module, $username);
				$modelIdMap = $modelLookup['map'];
				$duplicateModelNames = $modelLookup['duplicates'];

				$skippedInvalidModel = 0;
				$skippedAmbiguousModel = 0;
				$rowsWithResolvedModelIds = [];
				foreach ($combinedValidRows as $row) {
					$normalizedModel = $row['asset_model_normalized'];
					if (isset($duplicateModelNames[$normalizedModel])) {
						$skippedAmbiguousModel++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_model'],
							'reason' => 'Asset Model name is not unique; use Asset Model ID',
						];
						continue;
					}

					if (!isset($modelIdMap[$normalizedModel])) {
						$skippedInvalidModel++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_model'],
							'reason' => 'Asset Model does not exist',
						];
						continue;
					}

					$row['asset_model_id'] = $modelIdMap[$normalizedModel];
					$rowsWithResolvedModelIds[] = $row;
				}

				$serialsLower = array_map(function ($row) {
					return $row['asset_serial_number_normalized'];
				}, $rowsWithResolvedModelIds);

				$existingLower = $assetInfoObj->getExistingAssetInfoBySerials($serialsLower, $module, $username);
				$existingSet = array_fill_keys($existingLower, true);

				$infoToInsert = [];
				$skippedDuplicateDb = 0;
				foreach ($rowsWithResolvedModelIds as $row) {
					if (isset($existingSet[$row['asset_serial_number_normalized']])) {
						$skippedDuplicateDb++;
						$rowErrors[] = [
							'row' => $row['row'],
							'value' => $row['asset_serial_number'] . ' (Model: ' . $row['asset_model'] . ')',
							'reason' => 'Asset Info already exists',
						];
						continue;
					}

					$infoToInsert[] = [
						'asset_serial_number' => $row['asset_serial_number'],
						'asset_purchase_date' => $row['asset_purchase_date'],
						'asset_price' => $row['asset_price'],
						'asset_warranty_expiry' => $row['asset_warranty_expiry'],
						'asset_model_id' => $row['asset_model_id'],
					];
				}

				$rowErrors = ExcelImportHelper::sortRowErrors($rowErrors);

				if (!empty($infoToInsert)) {
					$inserted = $assetInfoObj->insertBatchAssetInfoFromExcel($infoToInsert, $username);
					if (!$inserted) {
						http_response_code(500);
						$error = ["error" => "Failed to import asset info from Excel"];
						echo json_encode($error);
						$logger->logRequestAndResponse(["file" => $fileName], $error);
						break;
					}
				}

				http_response_code(200);
				$response = [
					"message" => "Excel import completed",
					"total_rows" => $serialStats['total_rows'],
					"inserted" => count($infoToInsert),
					"skipped_null" => $serialStats['skipped_null'] + $purchaseDateAnalysis['stats']['skipped_null'] + $priceAnalysis['stats']['skipped_null'] + $warrantyAnalysis['stats']['skipped_null'] + $modelAnalysis['stats']['skipped_null'],
					"skipped_invalid" => $serialStats['skipped_invalid'] + $purchaseDateAnalysis['stats']['skipped_invalid'] + $priceAnalysis['stats']['skipped_invalid'] + $warrantyAnalysis['stats']['skipped_invalid'] + $modelAnalysis['stats']['skipped_invalid'],
					"skipped_duplicate_file" => $serialStats['skipped_duplicate_file'],
					"skipped_invalid_model" => $skippedInvalidModel,
					"skipped_ambiguous_model" => $skippedAmbiguousModel,
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

		if (!isset($input['asset_serial_number']) || empty(trim($input['asset_serial_number']))) {
			http_response_code(400);
			$error = ["error" => "Asset Serial Number is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_purchase_date']) || empty(trim($input['asset_purchase_date']))) {
			http_response_code(400);
			$error = ["error" => "Asset Purchase Date is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_price']) || !is_numeric($input['asset_price'])) {
			http_response_code(400);
			$error = ["error" => "Asset Price is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_warranty_expiry']) || empty(trim($input['asset_warranty_expiry']))) {
			http_response_code(400);
			$error = ["error" => "Asset Warranty Expiry is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}


		// only accept only if asset price is > 0
		if ($input['asset_price'] <= 0) {
			http_response_code(400);
			$error = ["error" => "Asset Price must be greater than zero"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		// only accept if warranty expiry date is greater than or equal to purchase date
		if (strtotime($input['asset_warranty_expiry']) < strtotime($input['asset_purchase_date'])) {
			http_response_code(400);
			$error = ["error" => "Asset Warranty Expiry must be greater than or equal to Asset Purchase Date"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$serialNumber = trim($input['asset_serial_number']);
		$purchaseDate = trim($input['asset_purchase_date']);
		$price = $input['asset_price'];
		$warrantyExpiry = trim($input['asset_warranty_expiry']);
		$assetModelId = null;
		if (isset($input['asset_model_id']) && is_numeric($input['asset_model_id'])) {
			$assetModelId = (int)$input['asset_model_id'];
		} elseif (isset($input['asset_model']) && trim($input['asset_model']) !== '') {
			$assetModelName = trim($input['asset_model']);
			if (!preg_match($regExp, $assetModelName)) {
				http_response_code(400);
				$error = ["error" => "Asset Model can only contain letters, numbers, underscores, hyphens, forward slashes and spaces"];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break;
			}

			$normalizedModel = strtolower(trim($assetModelName));
			$modelLookup = $assetModelObj->getAssetModelIdsByNames([$normalizedModel], $module, $username);
			if (isset($modelLookup['duplicates'][$normalizedModel])) {
				http_response_code(400);
				$error = ["error" => "Asset Model name is not unique; provide Asset Model ID"];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break;
			}

			if (!isset($modelLookup['map'][$normalizedModel])) {
				http_response_code(400);
				$error = ["error" => "Asset Model not found"];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break;
			}

			$assetModelId = $modelLookup['map'][$normalizedModel];
		} else {
			http_response_code(400);
			$error = ["error" => "Asset Model name or ID is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($regExp, $serialNumber)) {
			http_response_code(400);
			$error = ["error" => "Asset Serial Number can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($dateRegExp, $purchaseDate)) {
			http_response_code(400);
			$error = ["error" => "Asset Purchase Date must be in YYYY-MM-DD format"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($dateRegExp, $warrantyExpiry)) {
			http_response_code(400);
			$error = ["error" => "Asset Warranty Expiry must be in YYYY-MM-DD format"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($priceRegExp, (string)$price)) {
			http_response_code(400);
			$error = ["error" => "Asset Price must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$existingInfo = $assetInfoObj->isDuplicateAssetInfo($serialNumber, $module, $username);
		if ($existingInfo) {
			http_response_code(409);
			$error = ["error" => "Asset Info already exists"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetInfoObj->insertAssetInfo($serialNumber, $purchaseDate, $price, $warrantyExpiry, $assetModelId, $username, $module, $username);
		if ($result) {
			http_response_code(201);
			$response = ["message" => "Asset Info created successfully", "id" => $result];
			echo json_encode($response);
			$logger->logRequestAndResponse($input, $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to create Asset Info"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
		}

		break;

	case 'PUT':
		$logger->log("PUT request received");
		if (!isset($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Info ID is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Info ID must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		if (!isset($input['asset_serial_number']) || empty(trim($input['asset_serial_number']))) {
			http_response_code(400);
			$error = ["error" => "Asset Serial Number is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_purchase_date']) || empty(trim($input['asset_purchase_date']))) {
			http_response_code(400);
			$error = ["error" => "Asset Purchase Date is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_price']) || !is_numeric($input['asset_price'])) {
			http_response_code(400);
			$error = ["error" => "Asset Price is required and must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!isset($input['asset_warranty_expiry']) || empty(trim($input['asset_warranty_expiry']))) {
			http_response_code(400);
			$error = ["error" => "Asset Warranty Expiry is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}


		if (!preg_match($regExp, trim($input['asset_serial_number']))) {
			http_response_code(400);
			$error = ["error" => "Asset Serial Number can only contain letters, numbers and spaces"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($dateRegExp, trim($input['asset_purchase_date']))) {
			http_response_code(400);
			$error = ["error" => "Asset Purchase Date must be in YYYY-MM-DD format"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($dateRegExp, trim($input['asset_warranty_expiry']))) {
			http_response_code(400);
			$error = ["error" => "Asset Warranty Expiry must be in YYYY-MM-DD format"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($priceRegExp, (string)$input['asset_price'])) {
			http_response_code(400);
			$error = ["error" => "Asset Price must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$id = intval($_GET['id']);
		$serialNumber = trim($input['asset_serial_number']);
		$purchaseDate = trim($input['asset_purchase_date']);
		$price = $input['asset_price'];
		$warrantyExpiry = trim($input['asset_warranty_expiry']);
		$assetModelId = null;
		if (isset($input['asset_model_id']) && is_numeric($input['asset_model_id'])) {
			$assetModelId = (int)$input['asset_model_id'];
		} elseif (isset($input['asset_model']) && trim($input['asset_model']) !== '') {
			$assetModelName = trim($input['asset_model']);
			if (!preg_match($regExp, $assetModelName)) {
				http_response_code(400);
				$error = ["error" => "Asset Model can only contain letters, numbers, underscores, hyphens, forward slashes and spaces"];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break;
			}

			$normalizedModel = strtolower(trim($assetModelName));
			$modelLookup = $assetModelObj->getAssetModelIdsByNames([$normalizedModel], $module, $username);
			if (isset($modelLookup['duplicates'][$normalizedModel])) {
				http_response_code(400);
				$error = ["error" => "Asset Model name is not unique; provide Asset Model ID"];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break;
			}

			if (!isset($modelLookup['map'][$normalizedModel])) {
				http_response_code(400);
				$error = ["error" => "Asset Model not found"];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break;
			}

			$assetModelId = $modelLookup['map'][$normalizedModel];
		} else {
			http_response_code(400);
			$error = ["error" => "Asset Model name or ID is required"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$existingInfo = $assetInfoObj->isDuplicateAssetInfoForUpdate($id, $serialNumber, $module, $username);
		if ($existingInfo) {
			http_response_code(409);
			$error = ["error" => "Asset Info already exists"];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $assetInfoObj->updateAssetInfo($id, $serialNumber, $purchaseDate, $price, $warrantyExpiry, $assetModelId, $username, $module, $username);
		if ($result) {
			http_response_code(200);
			$response = ["message" => "Asset Info updated successfully"];
			echo json_encode($response);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to update Asset Info"];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
		}

		break;

	case 'DELETE':
		$logger->log("DELETE request received");
		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ["error" => "Asset Info ID must be a valid number"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		$id = intval($_GET['id']);

		$result = $assetInfoObj->deleteAssetInfo($id, $module, $username);

		if ($result) {
			http_response_code(200);
			$response = ["message" => "Asset Info deleted successfully"];
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
		} else {
			http_response_code(500);
			$error = ["error" => "Failed to delete Asset Info"];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
		}
		break;

	default:
		http_response_code(405);
		echo json_encode(['error' => 'Method Not Allowed']);
		break;
}
