<?php

declare(strict_types=1);

set_time_limit(0);

// IF YOU ADD ANY NEW COLUMN, REMEMBER THE COLUMN NAME IS AUTOMATICALLY CONVERTED TO LOWERCASE BY normalizeHeader() FUNCTION. 
// MAKE SURE YOU USE THE LOWERCASE COLUMN NAMES IN THE importUsers() AND importLicenses() FUNCTIONS BELOW.


// The csv files may be outside the project directory, so we allow passing the paths as command-line arguments or environment variables. If not provided, we fall back to default paths within the project.
const DEFAULT_USERS_CSV = __DIR__ . '/LicensedUsers.csv';
const DEFAULT_LICENSES_CSV = __DIR__ . '/Licenses.csv';
const APP_INI_PATH = __DIR__ . '/../app.ini';
const ENTITY = 'SCG';

$argvValues = $_SERVER['argv'] ?? [];
$usersCsvPath = resolveCsvPath($argvValues[1] ?? getenv('M365_USERS_CSV') ?? null, DEFAULT_USERS_CSV);
$licensesCsvPath = resolveCsvPath($argvValues[2] ?? getenv('M365_LICENSES_CSV') ?? null, DEFAULT_LICENSES_CSV);
$entity = $argvValues[3] ?? getenv('M365_ENTITY') ?? ENTITY;

try {
	$connection = createConnection(APP_INI_PATH);
	$users = readCsvAsRows($usersCsvPath);
	$licenses = readCsvAsRows($licensesCsvPath);

	$connection->beginTransaction();
	try {
		deleteUsersFromEntity($connection, $entity);
		deleteLicensesFromEntity($connection, $entity);
		importUsers($connection, $users, $entity);
		importLicenses($connection, $licenses, $entity);
		$connection->commit();
	} catch (Throwable $throwable) {
		if ($connection->inTransaction()) {
			$connection->rollBack();
		}
		throw $throwable;
	} finally {
		$connection = null;
	}
	echo 'Date: ' . date('Y-m-d H:i:s') . PHP_EOL;
	echo 'Import completed successfully.' . PHP_EOL;
	echo 'Users imported: ' . count($users) . PHP_EOL;
	echo 'License rows imported: ' . count($licenses) . PHP_EOL;
} catch (Throwable $throwable) {
	fwrite(STDERR, 'Import failed: ' . $throwable->getMessage() . PHP_EOL);
	exit(1);
}

function createConnection(string $iniPath): PDO
{
	if (!is_file($iniPath)) {
		throw new RuntimeException('INI file not found: ' . $iniPath);
	}

	$config = parse_ini_file($iniPath, true, INI_SCANNER_RAW);
	if ($config === false || !isset($config['database'])) {
		throw new RuntimeException('Unable to read database configuration from: ' . $iniPath);
	}

	$database = $config['database'];
	foreach (['host', 'db_name', 'db_user', 'db_password'] as $requiredKey) {
		if (!array_key_exists($requiredKey, $database)) {
			throw new RuntimeException('Missing database setting: ' . $requiredKey);
		}
	}

	$dsn = sprintf(
		'mysql:host=%s;dbname=%s;charset=utf8mb4',
		trim((string) $database['host'], "\"' "),
		trim((string) $database['db_name'], "\"' ")
	);

	$pdo = new PDO(
		$dsn,
		trim((string) $database['db_user'], "\"' "),
		trim((string) $database['db_password'], "\"' "),
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]
	);

	return $pdo;
}

function readCsvAsRows(string $csvPath): array
{
	if (!is_file($csvPath)) {
		throw new RuntimeException('CSV file not found: ' . $csvPath);
	}

	$handle = fopen($csvPath, 'rb');
	if ($handle === false) {
		throw new RuntimeException('Unable to open CSV file: ' . $csvPath);
	}

	$header = null;
	$rows = [];

	while (($line = fgetcsv($handle)) !== false) {
		if ($header === null) {
			$header = array_map(static fn(string $column): string => normalizeHeader($column), $line);
			continue;
		}

		if ($line === [null] || $line === false) {
			continue;
		}

		$row = [];
		foreach ($header as $index => $columnName) {
			$row[$columnName] = array_key_exists($index, $line) ? normalizeCell($line[$index]) : null;
		}

		if (hasAnyValue($row)) {
			$rows[] = $row;
		}
	}

	fclose($handle);

	if ($header === null) {
		throw new RuntimeException('CSV file has no header row: ' . $csvPath);
	}

	return $rows;
}

function resolveCsvPath(string|false|null $providedPath, string $defaultPath): string
{
	$resolvedPath = is_string($providedPath) ? normalizeCell($providedPath) : null;

	return $resolvedPath ?? $defaultPath;
}

function truncateTables(PDO $connection, array $tables): void
{
	$connection->exec('SET FOREIGN_KEY_CHECKS = 0');

	try {
		foreach ($tables as $table) {
			$connection->exec('TRUNCATE TABLE `' . $table . '`');
		}
	} finally {
		$connection->exec('SET FOREIGN_KEY_CHECKS = 1');
	}
}

function deleteUsersFromEntity(PDO $connection, string $entity): void
{
	$sql = 'DELETE FROM tbl_m365_users WHERE entity = :entity';
	$statement = $connection->prepare($sql);
	$statement->execute([':entity' => $entity]);
}

function deleteLicensesFromEntity(PDO $connection, string $entity): void
{
	$sql = 'DELETE FROM tbl_m365_licenses WHERE entity = :entity';
	$statement = $connection->prepare($sql);
	$statement->execute([':entity' => $entity]);
}

function importUsers(PDO $connection, array $users, string $entity): void
{
	$sql = <<<'SQL'
INSERT INTO tbl_m365_users (
	id,
	user_principal_name,
	first_name,
	last_name,
	display_name,
	department,
	job_title,
	office_location,
	city,
	country,
	mobile_number,
	phone_number,
	manager_name,
	manager_upn,
	mail_box_type,
	manager_id,
	entity,
	accountEnabled
) VALUES (
	:id,
	:user_principal_name,
	:first_name,
	:last_name,
	:display_name,
	:department,
	:job_title,
	:office_location,
	:city,
	:country,
	:mobile_number,
	:phone_number,
	:manager_name,
	:manager_upn,
	:mail_box_type,
	:manager_id,
	:entity,
	:accountEnabled
)
SQL;

	$statement = $connection->prepare($sql);

	foreach ($users as $row) {

		$statement->execute([
			':id' => requiredValue($row, 'id'),
			':user_principal_name' => $row['userprincipalname'] ?? null,
			':mail' => $row['mail'] ?? null,
			':first_name' => $row['firstname'] ?? null,
			':last_name' => $row['lastname'] ?? null,
			':display_name' => $row['displayname'] ?? null,
			':department' => $row['department'] ?? null,
			':job_title' => $row['jobtitle'] ?? null,
			':office_location' => $row['officelocation'] ?? null,
			':city' => $row['city'] ?? null,
			':country' => $row['country'] ?? null,
			':mobile_number' => $row['mobilenumber'] ?? null,
			':phone_number' => $row['phonenumber'] ?? null,
			':manager_name' => $row['managername'] ?? null,
			':manager_upn' => $row['managerupn'] ?? null,
			':mail_box_type' => $row['usermailboxtype'] ?? null,
			':manager_id' => $row['managerid'] ?? null,
			':entity' => $entity,
			':accountEnabled' => $row['accountenabled'] ?? null,
		]);
	}
}

function importLicenses(PDO $connection, array $licenses, string $entity): void
{
	$sql = <<<'SQL'
INSERT INTO tbl_m365_licenses (
	id,
	license_name,
	sku_value,
	entity
) VALUES (
	:id,
	:license_name,
	:sku_value,
	:entity
)
SQL;

	$statement = $connection->prepare($sql);

	foreach ($licenses as $row) {
		$statement->execute([
			':id' => requiredValue($row, 'id'),
			':license_name' => requiredValue($row, 'license'),
			':sku_value' => requiredValue($row, 'sku_value'),
			':entity' => $entity,
		]);
	}
}

function normalizeHeader(string $column): string
{
	$column = preg_replace('/^\xEF\xBB\xBF/', '', $column) ?? $column;
	$column = strtolower(trim($column));
	$column = preg_replace('/[^a-z0-9]+/', '_', $column) ?? $column;

	return trim($column, '_');
}

function normalizeCell(mixed $value): ?string
{
	if ($value === null) {
		return null;
	}

	$value = trim((string) $value);

	return $value === '' ? null : $value;
}

function hasAnyValue(array $row): bool
{
	foreach ($row as $value) {
		if ($value !== null) {
			return true;
		}
	}

	return false;
}

function requiredValue(array $row, string $key): string
{
	if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
		throw new RuntimeException('Missing required value for column: ' . $key);
	}

	return (string) $row[$key];
}
