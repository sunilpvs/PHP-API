<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}

require_once __DIR__ . '/../../../classes/admin/OfficeLocations.php';
require_once __DIR__ . '/../../../classes/authentication/middle.php';
require_once __DIR__ . '/../../../classes/Logger.php';
require_once __DIR__ . '/../../../classes/authentication/LoginUser.php';

authenticateJWT();

$config = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/app.ini');
$debugMode = isset($config['generic']['DEBUG_MODE']) && in_array(strtolower($config['generic']['DEBUG_MODE']), ['1', 'true'], true);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
$logger = new Logger($debugMode, $logDir);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
	$input = [];
}

$officeLocationObj = new OfficeLocations();
$auth = new UserLogin();
$username = $auth->getUserIdFromJWT() ? $auth->getUserIdFromJWT() : 'guest';
$module = 'Admin';

$nameRegExp = '/^[a-zA-Z0-9\s\-\.&,()\/]+$/';
$addressRegExp = '/^[a-zA-Z0-9\s\-\.#,()\/]+$/';
$zipRegExp = '/^[a-zA-Z0-9\-\s]+$/';

switch ($method) {
	case 'GET':
		$logger->log('GET request received');

		if (isset($_GET['id'])) {
			$id = intval($_GET['id']);
			$data = $officeLocationObj->getOfficeLocationById($id, $module, $username);
			$status = $data ? 200 : 404;
			$response = $data ?: ['error' => 'Office location not found'];
			http_response_code($status);
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
			break;
		}

		if (isset($_GET['type']) && $_GET['type'] === 'combo') {
			$fields = isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['id', 'name'];
			$fields = array_map('trim', $fields);
			$data = $officeLocationObj->getOfficeLocationCombo($fields, $module, $username);
			http_response_code(200);
			echo json_encode($data);
			$logger->logRequestAndResponse($_GET, $data);
			break;
		}

		$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
		$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
		$offset = ($page - 1) * $limit;

		$data = $officeLocationObj->getPaginatedOfficeLocations($offset, $limit, $module, $username);
		$total = $officeLocationObj->getOfficeLocationsCount($module, $username);

		$response = [
			'total' => $total,
			'page' => $page,
			'limit' => $limit,
			'office_locations' => $data,
		];

		http_response_code(200);
		echo json_encode($response);
		$logger->logRequestAndResponse($_GET, $response);
		break;

	case 'POST':
		$logger->log('POST request received');

		$requiredStringFields = ['name', 'city', 'state', 'zip', 'country', 'office'];
		foreach ($requiredStringFields as $field) {
			if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
				http_response_code(400);
				$error = ['error' => ucfirst($field) . ' is required'];
				echo json_encode($error);
				$logger->logRequestAndResponse($input, $error);
				break 2;
			}
		}

		if (isset($input['status']) && !is_numeric($input['status'])) {
			http_response_code(400);
			$error = ['error' => 'Status must be numeric'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$name = trim((string) $input['name']);
		$address = isset($input['address']) ? trim((string) $input['address']) : null;
		$city = trim((string) $input['city']);
		$state = trim((string) $input['state']);
		$zip = trim((string) $input['zip']);
		$country = trim((string) $input['country']);
		$office = trim((string) $input['office']);
		$status = isset($input['status']) ? intval($input['status']) : 1;
		$createdBy = is_numeric($username) ? intval($username) : 0;

		if (!preg_match($nameRegExp, $name)) {
			http_response_code(400);
			$error = ['error' => 'Name has invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if ($address !== null && $address !== '' && !preg_match($addressRegExp, $address)) {
			http_response_code(400);
			$error = ['error' => 'Address has invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($nameRegExp, $city) || !preg_match($nameRegExp, $state) || !preg_match($nameRegExp, $country) || !preg_match($nameRegExp, $office)) {
			http_response_code(400);
			$error = ['error' => 'City, state, country, and office contain invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		if (!preg_match($zipRegExp, $zip)) {
			http_response_code(400);
			$error = ['error' => 'Zip has invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$duplicate = $officeLocationObj->checkDuplicateOfficeLocation($name, $city, $state, $country, $office);
		if ($duplicate) {
			http_response_code(400);
			$error = ['error' => 'Duplicate Record: Office location already exists'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
			break;
		}

		$result = $officeLocationObj->insertOfficeLocation($name, $address, $city, $state, $zip, $country, $office, $status, $createdBy, $module, $username);

		if ($result) {
			http_response_code(201);
			$response = ['message' => 'Office location added successfully', 'id' => (int) $result];
			echo json_encode($response);
			$logger->logRequestAndResponse($input, $response);
		} else {
			http_response_code(500);
			$error = ['error' => 'Failed to add office location'];
			echo json_encode($error);
			$logger->logRequestAndResponse($input, $error);
		}
		break;

	case 'PUT':
		$logger->log('PUT request received');

		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ['error' => 'Office location ID must be a valid number'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		$requiredStringFields = ['name', 'city', 'state', 'zip', 'country', 'office'];
		foreach ($requiredStringFields as $field) {
			if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
				http_response_code(400);
				$error = ['error' => ucfirst($field) . ' is required'];
				echo json_encode($error);
				$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
				break 2;
			}
		}

		if (isset($input['status']) && !is_numeric($input['status'])) {
			http_response_code(400);
			$error = ['error' => 'Status must be numeric'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		$id = intval($_GET['id']);
		$name = trim((string) $input['name']);
		$address = isset($input['address']) ? trim((string) $input['address']) : null;
		$city = trim((string) $input['city']);
		$state = trim((string) $input['state']);
		$zip = trim((string) $input['zip']);
		$country = trim((string) $input['country']);
		$office = trim((string) $input['office']);
		$status = isset($input['status']) ? intval($input['status']) : 1;
		$lastUpdated = is_numeric($username) ? intval($username) : 0;

		if (!preg_match($nameRegExp, $name)) {
			http_response_code(400);
			$error = ['error' => 'Name has invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		if ($address !== null && $address !== '' && !preg_match($addressRegExp, $address)) {
			http_response_code(400);
			$error = ['error' => 'Address has invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		if (!preg_match($nameRegExp, $city) || !preg_match($nameRegExp, $state) || !preg_match($nameRegExp, $country) || !preg_match($nameRegExp, $office)) {
			http_response_code(400);
			$error = ['error' => 'City, state, country, and office contain invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		if (!preg_match($zipRegExp, $zip)) {
			http_response_code(400);
			$error = ['error' => 'Zip has invalid characters'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		$duplicate = $officeLocationObj->checkEditDuplicateOfficeLocation($name, $city, $state, $country, $office, $id);
		if ($duplicate) {
			http_response_code(400);
			$error = ['error' => 'Duplicate Record: Office location already exists'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
			break;
		}

		$result = $officeLocationObj->updateOfficeLocation($name, $address, $city, $state, $zip, $country, $office, $status, $id, $lastUpdated, $module, $username);

		if ($result !== false) {
			http_response_code(200);
			$response = ['message' => $result > 0 ? 'Office location updated successfully' : 'No changes made'];
			echo json_encode($response);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $response);
		} else {
			http_response_code(500);
			$error = ['error' => 'Failed to update office location'];
			echo json_encode($error);
			$logger->logRequestAndResponse(array_merge($_GET, $input), $error);
		}
		break;

	case 'DELETE':
		$logger->log('DELETE request received');

		if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
			http_response_code(400);
			$error = ['error' => 'Office location ID is required'];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
			break;
		}

		$id = intval($_GET['id']);
		$result = $officeLocationObj->deleteOfficeLocation($id, $module, $username);

		if ($result > 0) {
			http_response_code(200);
			$response = ['message' => 'Office location deleted successfully'];
			echo json_encode($response);
			$logger->logRequestAndResponse($_GET, $response);
		} else {
			http_response_code(500);
			$error = ['error' => 'Failed to delete office location'];
			echo json_encode($error);
			$logger->logRequestAndResponse($_GET, $error);
		}
		break;

	default:
		http_response_code(405);
		$error = ['error' => 'Method not allowed'];
		echo json_encode($error);
		$logger->logRequestAndResponse($_SERVER, $error);
		break;
}

?>
