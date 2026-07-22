<?php
// this file is to provide helper functions for Excel import operations
// it reads a configuration file 

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;

require_once __DIR__ . '/../DbController.php';
require_once __DIR__ . '/../../vendor/autoload.php';


class ExcelHelper
{
    private DBController $conn;
    private $config;
    private $sheetName;
    private $tableName;
    private $module;
    private $mapping = [];
    private $dateColumns = [];
    private $otherDbColumns = [];

    public function __construct($configFilePath)
    {
        if (!file_exists($configFilePath)) {
            throw new Exception("Configuration file not found: $configFilePath");
        }
        $this->config = parse_ini_file($configFilePath, true);
        $this->conn = new DBController();
        $this->sheetName = $this->config['sheet']['sheet-name'] ?? 'Sheet1';
        $this->tableName = $this->config['database']['table-name'] ?? null;
        $this->mapping = $this->config['excel-to-database-mapping'] ?? [];
        $this->dateColumns = $this->config['date-columns'] ?? [];
        $this->module = $this->config['module']['name'] ?? null;
        $this->otherDbColumns = $this->config['other-db-columns'] ?? [];
    }

    // table creation function - create a temporary table with the columns 
    // create table if not exists
    // all the columns are of type VARCHAR(255) for simplicity
    // table name format : tbl_tmp_<module_name>
    public function createTemporaryTable()
    {
        if (!$this->module) {
            throw new Exception("Module name is not defined in the configuration.");
        }
        $columns = [];
        // add a batch_id column to the temporary table
        $columns[] = "`batch_id` VARCHAR(255)";
        foreach ($this->mapping as $excelColumn => $dbColumn) {
            $columns[] = "`$dbColumn` VARCHAR(255)";
        }
        // add the other db columns to the temporary table
        if (!empty($this->otherDbColumns)) {
            foreach ($this->otherDbColumns as $dbColumn) {
                $columns[] = "`$dbColumn` VARCHAR(255)";
            }
        }

        $columnsSql = implode(", ", $columns);
        $tempTableName = "tbl_tmp_" . strtolower($this->module);
        $query = "CREATE TABLE IF NOT EXISTS `$tempTableName` ($columnsSql)";
        $this->conn->createTable($query);
    }

    // import function - to import the Excel file into the temporary table
    // import the rows from the Excel file into the temporary table 
    // all the rows are inserted as is, without any validation or transformation
    // all the rows should be inserted with a batch id
    // excel file is uploaded not as a path but as a file object
    // excel file is read using PhpSpreadsheet library
    // the excel file is sent by POST api multipart/form-data request
    public function importExcelToTemporaryTable($file)
    {
        if (!$this->module) {
            throw new Exception("Module name is not defined in the configuration.");
        }


        $tempTableName = "tbl_tmp_" . strtolower($this->module);
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file['tmp_name']);
        $sheet = $spreadsheet->getSheetByName($this->sheetName);

        if (!$sheet) {
            throw new Exception("Sheet not found: " . $this->sheetName);
        }

        $rows = $sheet->toArray();

        // trim the headers and rows to remove any leading/trailing whitespace
        $rows = array_map(function ($row) {
            return array_map('trim', $row);
        }, $rows);


        if (empty($rows)) {
            throw new Exception("Excel sheet is empty.");
        }

        $headers = array_shift($rows);
        $batchId = uniqid('import_', true);

        // Get DB columns once
        $dbColumns = array_merge(
            array_values($this->mapping),
            ["batch_id"]
        );

        $allValues = [];


        foreach ($rows as $row) {

            if (count($headers) !== count($row)) continue;

            if (count(array_filter($row)) === 0) {
                continue;
            }

            $excelData = array_combine($headers, $row);

            $data = [];

            foreach ($this->mapping as $excelColumn => $dbColumn) {
                $data[$dbColumn] = $excelData[$excelColumn] ?? null;

                if (in_array($dbColumn, array_values($this->dateColumns), true)) {
                    $data[$dbColumn] = $this->parseDateValue($data[$dbColumn]);
                }
            }

            $data['batch_id'] = $batchId;

            // Build value row
            $values = array_map(function ($value) {
                if ($value === null) return "NULL";
                return "'" . addslashes($value) . "'";
            }, array_values($data));

            $allValues[] = "(" . implode(", ", $values) . ")";
        }

        if (empty($allValues)) {
            throw new Exception("No valid rows to insert.");
        }

        $columns = implode(", ", array_map(fn($c) => "`$c`", $dbColumns));

        $sql = "INSERT INTO `$tempTableName` ($columns) VALUES " . implode(", ", $allValues);

        $this->conn->runQuery($sql);

        return $batchId;
    }

    // selection function - to select the rows from the temporary table for validation
    // select the rows from the temporary table with the batch id
    public function selectTemporaryTableRows($batchId)
    {
        if (!$this->module) {
            throw new Exception("Module name is not defined in the configuration.");
        }
        $tempTableName = "tbl_tmp_" . strtolower($this->module);
        $query = "SELECT * FROM `$tempTableName` WHERE `batch_id` = '$batchId'";
        return $this->conn->runQuery($query);
    }

    // clean up function - to clean the temporary table after the import is done
    // called after the validation is done and the data is inserted into the main table
    public function cleanTemporaryTable($batchId)
    {
        if (!$this->module) {
            throw new Exception("Module name is not defined in the configuration.");
        }
        $tempTableName = "tbl_tmp_" . strtolower($this->module);
        $query = "DELETE FROM `$tempTableName` WHERE `batch_id` = '$batchId'";
        $this->conn->runQuery($query);
    }

    // return the table name of the main table from the configuration file
    public function getMainTableName()
    {
       return $this->tableName;
    }

    private function parseDateValue($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                return Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Exception $e) {
                return $raw;
            }
        }

        $formats = [
            'Y-m-d',
            'Y/m/d',
            'd-m-Y',
            'd/m/Y',
            'm-d-Y',
            'm/d/Y',
            'n-j-Y',
            'j-n-Y',
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $raw);
            $errors = \DateTime::getLastErrors();
            if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $dt->format('Y-m-d');
            }
        }

        return $raw;
    }

    public function generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb) {
        $errorReport = [];

        if (!empty($duplicateRowsInExcelFile)) {
            $errorReport['duplicates_in_excel'] = $duplicateRowsInExcelFile;
        }

        if (!empty($duplicateRowsInDb)) {
            $errorReport['duplicates_in_db'] = $duplicateRowsInDb;
        }

        return $errorReport;
    }
}
