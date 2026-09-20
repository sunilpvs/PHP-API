<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/hr/Salaries.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/middle.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/Logger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/authentication/LoginUser.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/utils/ExcelTemplateHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$debugMode = isset($config['generic']['DEBUG_MODE'])
	&& in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$salaryOb = new Salaries();
$salaryConfigFilePath = $_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/salaries.ini';
$incrementConfigFilePath = $_SERVER['DOCUMENT_ROOT'] . '/excel-config/hr/increments.ini';
$salaryExcelHelper = new ExcelHelper($salaryConfigFilePath);
$incrementExcelHelper = new ExcelHelper($incrementConfigFilePath);
$salaryTemplateHelper = new ExcelTemplateHelper($salaryConfigFilePath);
$incrementTemplateHelper = new ExcelTemplateHelper($incrementConfigFilePath);

$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ?: 'guest';
$module = 'Human Resource Management';

function salaryApiRespond($statusCode, array $response, $request, $logger): void
{
	http_response_code($statusCode);
	echo json_encode($response);
	$logger->logRequestAndResponse($request, $response);
}

switch ($method) {
	case 'GET':
		$logger->log('GET request received');

		try {
			if (isset($_GET['download-salary-template']) && $_GET['download-salary-template'] === 'true') {
				$salaryTemplateHelper->generateTemplate();
				salaryApiRespond(200, ['message' => 'Salary template generated successfully.'], $_GET, $logger);
				break;
			}

			if (isset($_GET['download-increments-template']) && $_GET['download-increments-template'] === 'true') {
				$incrementTemplateHelper->generateTemplate();
				salaryApiRespond(200, ['message' => 'Increment template generated successfully.'], $_GET, $logger);
				break;
			}

			if (isset($_GET['employee_id'])) {
				if (!is_numeric($_GET['employee_id']) || (int) $_GET['employee_id'] <= 0) {
					salaryApiRespond(400, ['error' => 'Employee ID must be a valid positive number.'], $_GET, $logger);
					break;
				}

				$employeeId = (int) $_GET['employee_id'];
				if (isset($_GET['active']) && strtolower((string) $_GET['active']) === 'true') {
					$salary = $salaryOb->getActiveSalaryByEmployeeId($employeeId, $module, $username);
					salaryApiRespond($salary ? 200 : 404, $salary ?: ['error' => 'Active salary not found.'], $_GET, $logger);
					break;
				}

				$salaries = $salaryOb->getSalariesByEmployeeId($employeeId, $module, $username);
				salaryApiRespond(200, ['salaries' => $salaries], $_GET, $logger);
				break;
			}

			$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
			$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
			$offset = ($page - 1) * $limit;
			$salaries = $salaryOb->getPaginatedSalaries($offset, $limit, $module, $username);

			// The model's count method is employee-scoped, so count active rows here for pagination.
			$activeSalaries = $salaryOb->getAllSalaries($module, $username);
			$activeCount = count(array_filter($activeSalaries, static function ($salary) {
				return isset($salary['status_id']) && (int) $salary['status_id'] === 1;
			}));

			salaryApiRespond(200, [
				'total' => $activeCount,
				'page' => $page,
				'limit' => min(100, $limit),
				'salaries' => $salaries,
			], $_GET, $logger);
		} catch (Exception $exception) {
			salaryApiRespond(500, ['error' => $exception->getMessage()], $_GET, $logger);
		}
		break;

	case 'POST':
		$logger->log('POST request received');

		if (isset($_POST['import-type']) || isset($_FILES['file'])) {
			$importType = strtolower(trim((string) ($_POST['import-type'] ?? '')));
			if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
				salaryApiRespond(400, ['error' => 'A valid Excel file is required.'], $_POST, $logger);
				break;
			}

			try {
				$file = $_FILES['file'];
				if ($importType === 'salaries') {
					$salaryExcelHelper->createTemporaryTable();
					$batchId = $salaryExcelHelper->importExcelToTemporaryTable($file);
					try {
						$errorReport = $salaryOb->importSalariesFromExcel($batchId, $module, $username);
					} finally {
						$salaryExcelHelper->cleanTemporaryTable($batchId);
					}
				} elseif ($importType === 'increments') {
					$incrementExcelHelper->createTemporaryTable();
					$batchId = $incrementExcelHelper->importExcelToTemporaryTable($file);
					try {
						$errorReport = $salaryOb->importIncrementsFromExcel($batchId, $module, $username);
					} finally {
						$incrementExcelHelper->cleanTemporaryTable($batchId);
					}
				} else {
					salaryApiRespond(400, ['error' => 'Import type must be salaries or increments.'], $_POST, $logger);
					break;
				}

				salaryApiRespond(200, [
					'message' => 'Salary data imported successfully.',
					'errors' => $errorReport,
				], $_POST, $logger);
			} catch (Exception $exception) {
				salaryApiRespond(500, ['error' => $exception->getMessage()], $_POST, $logger);
			}
			break;
		}

		$requiredFields = ['employee_id', 'gross', 'effective_from', 'revision_type'];
		foreach ($requiredFields as $field) {
			if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
				salaryApiRespond(400, ['error' => $field . ' is required.'], $input, $logger);
				break 2;
			}
		}

		if (!isset($input['status']) || !in_array((string) $input['status'], ['1', '2'], true)) {
			salaryApiRespond(400, ['error' => 'Status must be either 1 (Active) or 2 (In-Active).'], $input, $logger);
			break;
		}

		try {
			$result = $salaryOb->addSalaryRecord(
				(int) $input['employee_id'],
				$input['gross'],
				$input['increment'] ?? null,
				$input['effective_from'],
				$input['revision_type'],
				$input['notes'] ?? null,
				$username,
				$module,
				$username,
				(int) $input['status']
			);
			salaryApiRespond(201, $result, $input, $logger);
		} catch (InvalidArgumentException $exception) {
			salaryApiRespond(400, ['error' => $exception->getMessage()], $input, $logger);
		} catch (Exception $exception) {
			salaryApiRespond(500, ['error' => $exception->getMessage()], $input, $logger);
		}
		break;

	case 'PUT':
		salaryApiRespond(405, ['error' => 'PUT is not supported. Salary changes must be added as a new revision.'], $input, $logger);
		break;

	case 'DELETE':
		salaryApiRespond(405, ['error' => 'DELETE is not supported for salary history.'], $_GET, $logger);
		break;

	default:
		salaryApiRespond(405, ['error' => 'Method not allowed.'], ['method' => $method], $logger);
		break;
}
