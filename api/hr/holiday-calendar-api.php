<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/hr/HolidayCalendar.php';
require_once __DIR__ . '/../../classes/authentication/middle.php';
require_once __DIR__ . '/../../classes/Logger.php';
require_once __DIR__ . '/../../classes/authentication/LoginUser.php';
require_once __DIR__ . '/../../classes/utils/ExcelImportHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini', true);
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$holidayCalendar = new HolidayCalendar();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'Guest user';
$module = 'Human Resource Management';

$holidayNameRegExp = '/^[a-zA-Z0-9\s\-\&\(\)\.\/]+$/';

function normalizeHolidayDate($dateValue)
{
    if ($dateValue === null || $dateValue === '') {
        return null;
    }

    if (is_numeric($dateValue)) {
        try {
            return ExcelDate::excelToDateTimeObject($dateValue)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    $timestamp = strtotime((string)$dateValue);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function parseBranchesInput($branchesInput)
{
    if (is_array($branchesInput)) {
        $items = $branchesInput;
    } else {
        $items = explode(',', (string)$branchesInput);
    }

    $items = array_map('trim', $items);
    $items = array_values(array_filter($items, static fn($value) => $value !== ''));
    return array_values(array_unique($items));
}

function getUploadFileKey()
{
    if (isset($_FILES['excel_file'])) {
        return 'excel_file';
    }

    if (isset($_FILES['file'])) {
        return 'file';
    }

    return null;
}

function readHolidayImportRows($filePath, $extension)
{
    if ($extension === 'csv') {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        $headerMap = [];
        foreach ($header as $index => $columnName) {
            $headerMap[$index] = strtolower(trim((string)$columnName));
        }

        $rows = [];
        $line = 2;
        while (($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($headerMap as $index => $columnName) {
                $row[$columnName] = $data[$index] ?? '';
            }
            $rows[$line] = $row;
            $line++;
        }

        fclose($handle);
        return $rows;
    }

    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getSheetByName('Holiday-Calendar');
    if ($sheet === null) {
        throw new RuntimeException('Sheet Holiday-Calendar not found in the uploaded Excel file');
    }

    $rawRows = $sheet->toArray(null, true, true, true);
    if (empty($rawRows) || !isset($rawRows[1])) {
        return [];
    }

    $headerRow = $rawRows[1];
    $holidayNameCol = ExcelImportHelper::findHeaderColumn($headerRow, 'holiday_name');
    $holidayDateCol = ExcelImportHelper::findHeaderColumn($headerRow, 'holiday_date');
    $branchesCol = ExcelImportHelper::findHeaderColumn($headerRow, 'branches');
    $descriptionCol = ExcelImportHelper::findHeaderColumn($headerRow, 'description');

    if ($branchesCol === null) {
        $branchesCol = ExcelImportHelper::findHeaderColumn($headerRow, 'branch');
    }

    $rows = [];
    foreach ($rawRows as $index => $row) {
        if ($index === 1) {
            continue;
        }

        $rows[$index] = [
            'holiday_name' => $holidayNameCol !== null ? ($row[$holidayNameCol] ?? '') : '',
            'holiday_date' => $holidayDateCol !== null ? ($row[$holidayDateCol] ?? '') : '',
            'branches' => $branchesCol !== null ? ($row[$branchesCol] ?? '') : '',
            'description' => $descriptionCol !== null ? ($row[$descriptionCol] ?? '') : '',
        ];
    }

    return $rows;
}

switch ($method) {
    case 'GET':
        $logger->log('Received GET request for holiday calendar', 'api', $module, $username);

        if (isset($_GET['id'])) {
            if (!is_numeric($_GET['id'])) {
                http_response_code(400);
                $error = ['error' => 'Invalid ID format'];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error);
                break;
            }

            $result = $holidayCalendar->getHolidayById((int)$_GET['id'], $module, $username);
            if ($result) {
                http_response_code(200);
                echo json_encode($result);
                $logger->logRequestAndResponse($_GET, $result);
            } else {
                http_response_code(404);
                $error = ['error' => 'Holiday not found'];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error);
            }
            break;
        }

        if (isset($_GET['branch'])) {
            $result = $holidayCalendar->getHolidaysByBranch($_GET['branch'], $module, $username);
            http_response_code(200);
            echo json_encode($result);
            $logger->logRequestAndResponse($_GET, $result);
            break;
        }

        if (isset($_GET['month']) && isset($_GET['year'])) {
            $month = (int)$_GET['month'];
            $year = (int)$_GET['year'];

            if ($month < 1 || $month > 12 || $year < 1) {
                http_response_code(400);
                $error = ['error' => 'Invalid month or year format'];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error);
                break;
            }

            $result = $holidayCalendar->getHolidaysByMonthAndYear($month, $year, $module, $username);
            http_response_code(200);
            echo json_encode($result);
            $logger->logRequestAndResponse($_GET, $result);
            break;
        }

        if (isset($_GET['type']) && $_GET['type'] === 'branches') {
            $result = $holidayCalendar->getAllBranches($module, $username);
            http_response_code(200);
            echo json_encode($result);
            $logger->logRequestAndResponse($_GET, $result);
            break;
        }

        

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $data = $holidayCalendar->getPaginatedHolidays($offset, $limit, $module, $username);
        $total = $holidayCalendar->getHolidaysCount($module, $username);

        $response = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'data' => $data,
        ];

        http_response_code(200);
        echo json_encode($response);
        $logger->logRequestAndResponse($_GET, $response);
        break;

    case 'POST':
        $logger->log('Received POST request for holiday calendar', 'api', $module, $username);

        $fileKey = getUploadFileKey();
        if ($fileKey !== null) {
            if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                $error = ['error' => 'File upload failed'];
                echo json_encode($error);
                $logger->logRequestAndResponse($_FILES, $error);
                break;
            }

            $fileName = $_FILES[$fileKey]['name'];
            $fileTmp = $_FILES[$fileKey]['tmp_name'];
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                http_response_code(400);
                $error = ['error' => 'Only .xlsx, .xls or .csv files are allowed'];
                echo json_encode($error);
                $logger->logRequestAndResponse(['file' => $fileName], $error);
                break;
            }

            if ($extension === 'xlsx' && !class_exists('ZipArchive')) {
                http_response_code(500);
                $error = [
                    'error' => 'Server dependency missing: PHP ZipArchive extension is required to import .xlsx files',
                    'hint' => 'Enable php_zip or upload .csv/.xls instead',
                ];
                echo json_encode($error);
                $logger->logRequestAndResponse(['file' => $fileName], $error);
                break;
            }

            try {
                $rows = readHolidayImportRows($fileTmp, $extension);

                if (empty($rows)) {
                    http_response_code(400);
                    $error = ['error' => 'Import file is empty'];
                    echo json_encode($error);
                    $logger->logRequestAndResponse(['file' => $fileName], $error);
                    break;
                }

                $rowErrors = [];
                $insertedRows = 0;
                $insertedMappings = 0;
                $importResults = [];

                foreach ($rows as $rowNumber => $row) {
                    $holidayName = trim((string)($row['holiday_name'] ?? ''));
                    $holidayDate = normalizeHolidayDate($row['holiday_date'] ?? '');
                    $branches = parseBranchesInput($row['branches'] ?? '');
                    $description = trim((string)($row['description'] ?? ''));

                    if ($holidayName === '' || $holidayDate === null || empty($branches)) {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'reason' => 'holiday_name, holiday_date and branches are required',
                        ];
                        continue;
                    }

                    if (!preg_match($holidayNameRegExp, $holidayName)) {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'value' => $holidayName,
                            'reason' => 'Invalid holiday_name format',
                        ];
                        continue;
                    }

                    $result = $holidayCalendar->addHoliday($holidayName, $holidayDate, $branches, $description, $module, $username);
                    $importResults[] = $result;

                    if (!empty($result['success'])) {
                        $insertedRows++;
                        $insertedMappings += count($result['inserted_branches'] ?? []);

                        foreach (($result['skipped_branches'] ?? []) as $skippedBranch) {
                            $rowErrors[] = [
                                'row' => $rowNumber,
                                'value' => $skippedBranch['branch'] ?? null,
                                'reason' => $skippedBranch['reason'] ?? 'Skipped branch',
                            ];
                        }
                    } else {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'value' => $branches,
                            'reason' => $result['message'] ?? 'No branches were inserted',
                        ];
                    }
                }

                $response = [
                    'message' => 'Import completed',
                    'inserted_rows' => $insertedRows,
                    'inserted_branch_mappings' => $insertedMappings,
                    'row_errors' => ExcelImportHelper::sortRowErrors($rowErrors),
                    'results' => $importResults,
                ];

                http_response_code(200);
                echo json_encode($response);
                $logger->logRequestAndResponse(['file' => $fileName], $response);
            } catch (Throwable $e) {
                http_response_code(500);
                $error = ['error' => 'Failed to process import file', 'details' => $e->getMessage()];
                echo json_encode($error);
                $logger->log('Holiday import error: ' . $e->getMessage(), 'api', $module, $username);
                $logger->logRequestAndResponse(['file' => $fileName], $error);
            }

            break;
        }

        $holidayName = trim((string)($input['holiday_name'] ?? ''));
        $holidayDate = normalizeHolidayDate($input['holiday_date'] ?? '');
        $branches = parseBranchesInput($input['branches'] ?? []);
        $description = isset($input['description']) ? trim((string)$input['description']) : '';

        if ($holidayName === '' || $holidayDate === null || empty($branches)) {
            http_response_code(400);
            $error = ['error' => 'Holiday name, date, and branches are required'];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

        if (!preg_match($holidayNameRegExp, $holidayName)) {
            http_response_code(400);
            $error = ['error' => 'Holiday name can only contain letters, numbers, spaces, hyphens, ampersands, parentheses, dots, and slashes'];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
            break;
        }

    //   500 error code for duplicate entry

        try {
            $result = $holidayCalendar->addHoliday($holidayName, $holidayDate, $branches, $description, $module, $username);
            if (!empty($result['success'])) {
                http_response_code(201);
                $response = [
                    'message' => $result['message'] ?? 'Holiday added successfully',
                    'id' => $result['holiday_id'] ?? null,
                    'inserted_branches' => $result['inserted_branches'] ?? [],
                    'skipped_branches' => $result['skipped_branches'] ?? [],
                ];
                echo json_encode($response);
                $logger->logRequestAndResponse($input, $response);
            } else {
                http_response_code(409);
                $error = [
                    'error' => $result['message'] ?? 'Holiday could not be added',
                    'skipped_branches' => $result['skipped_branches'] ?? [],
                ];
                echo json_encode($error);
                $logger->logRequestAndResponse($input, $error);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            $error = ['error' => "A holiday already exists for the selected date and branches"];
            echo json_encode($error);
            $logger->logRequestAndResponse($input, $error);
        }
        break;

    case 'PUT':
        $logger->log('Received PUT request for holiday calendar', 'api', $module, $username);

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ['error' => 'Valid holiday id is required'];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $id = (int)$_GET['id'];
        $existing = $holidayCalendar->getHolidayById($id, $module, $username);
        if (!$existing) {
            http_response_code(404);
            $error = ['error' => 'Holiday not found'];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        $holidayName = trim((string)($input['holiday_name'] ?? ''));
        $holidayDate = normalizeHolidayDate($input['holiday_date'] ?? '');
        $branches = parseBranchesInput($input['branches'] ?? []);
        $description = isset($input['description']) ? trim((string)$input['description']) : '';

        if ($holidayName === '' || $holidayDate === null || empty($branches)) {
            http_response_code(400);
            $error = ['error' => 'Holiday name, date, and branches are required'];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        if (!preg_match($holidayNameRegExp, $holidayName)) {
            http_response_code(400);
            $error = ['error' => 'Invalid holiday_name format'];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            break;
        }

        try {
            $result = $holidayCalendar->updateHoliday($id, $holidayName, $holidayDate, $branches, $description, $module, $username);
            if (!empty($result['success'])) {
                http_response_code(200);
                $response = [
                    'message' => 'Holiday updated successfully',
                    'updated_rows' => $result['updated_rows'] ?? 0,
                    'inserted_branches' => $result['inserted_branches'] ?? [],
                    'skipped_branches' => $result['skipped_branches'] ?? [],
                ];
                echo json_encode($response);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $response);
            } else {
                http_response_code(409);
                $error = [
                    'error' => $result['message'] ?? 'Holiday could not be updated',
                    'skipped_branches' => $result['skipped_branches'] ?? [],
                ];
                echo json_encode($error);
                $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            $error = ['error' => 'Failed to update holiday', 'details' => $e->getMessage()];
            echo json_encode($error);
            $logger->logRequestAndResponse(array_merge($_GET, $input), $error);
        }
        break;

    case 'DELETE':
        $logger->log('Received DELETE request for holiday calendar', 'api', $module, $username);

        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            $error = ['error' => 'Valid holiday id is required'];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        $id = (int)$_GET['id'];
        $existing = $holidayCalendar->getHolidayById($id, $module, $username);
        if (!$existing) {
            http_response_code(404);
            $error = ['error' => 'Holiday not found'];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
            break;
        }

        try {
            $deletedRows = $holidayCalendar->deleteHoliday($id, $module, $username);
            if ($deletedRows > 0) {
                http_response_code(200);
                $response = ['message' => 'Holiday deleted successfully'];
                echo json_encode($response);
                $logger->logRequestAndResponse($_GET, $response);
            } else {
                http_response_code(500);
                $error = ['error' => 'Failed to delete holiday'];
                echo json_encode($error);
                $logger->logRequestAndResponse($_GET, $error);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            $error = ['error' => 'Failed to delete holiday', 'details' => $e->getMessage()];
            echo json_encode($error);
            $logger->logRequestAndResponse($_GET, $error);
        }
        break;

    default:
        http_response_code(405);
        $error = ['error' => 'Method Not Allowed'];
        echo json_encode($error);
        $logger->logRequestAndResponse(['method' => $method], $error);
        break;
}