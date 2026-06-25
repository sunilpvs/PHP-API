<?php

require_once __DIR__ . "/ExcelHelper.php";
require_once __DIR__ . "/../../vendor/autoload.php";
require_once __DIR__ . "/../../classes/DbController.php";

class MainInsertionSample{

    private ExcelHelper $helper;
    private $conn;

    public function __construct(){
        $this->helper = new ExcelHelper(__DIR__ . "/../../api/hr/import.ini");
        $this->conn = new DBController();
    }

    // function to insert the data into the table from temp table
    // get the data from temp table using the batch id
    // validate the data 
    // insert the data into the table
    public function insertDataFromTempTable($batchId){
        $rows = $this->helper->selectTemporaryTableRows($batchId);
        $tableName = $this->helper->getMainTableName();
        if(empty($rows)){
            throw new Exception("No data found in temporary table for batch id: $batchId");
        }

        // duplicate rows in the excel file are not allowed
        // store duplicate rows for error reporting with the row number in the excel file
        $rowNumber = 1; // Assuming the first row is the header
        $cleanedRows = [];
        $duplicateRowsInExcelFile = [];
        foreach($rows as $row){
            $key = $row['name'] . '_' . $row['rno'];
            if (isset($cleanedRows[$key])) {
                $duplicateRowsInExcelFile[] = ['row_number' => $rowNumber, 'data' => ['name' => $row['name'], 'rno' => $row['rno']]];
            } else {
                $cleanedRows[$key] = $row;
            }
            $rowNumber++;
        }
        $duplicateRowsInDb = [];

        // get the existing rows from the main table to check for duplicates
        $existingRows = $this->conn->runQuery("SELECT name, rno FROM $tableName");
        $existingRowsMap = [];

        foreach($existingRows as $existingRow){
            $key = $existingRow['name'] . '_' . $existingRow['rno'];
            $existingRowsMap[$key] = true;
        }

        foreach($cleanedRows as $row){
            // validate the data
            if ($row['is_active'] !== 'Active' && $row['is_active'] !== 'Inactive') {
                throw new Exception("Invalid value for is_active: " . $row['is_active']);
            }
            $row['is_active'] = $row['is_active'] === 'Active' ? 1 : 0;

            // check for duplicates in the main table, if found, skip the insertion and log the duplicate
            if (isset($existingRowsMap[$row['name'] . '_' . $row['rno']])) {
                // Log the duplicate row
                $duplicateRowsInDb[] = ['name' => $row['name'], 'rno' => $row['rno']];
                continue; // Skip this row
            }

            
            // insert the data into the table
            $this->conn->runQuery("INSERT INTO $tableName (name, rno, is_active) VALUES (:name, :rno, :is_active)", [
                ":name" => trim($row['name']),
                ":rno" => intval($row['rno']),
                ":is_active" => $row['is_active']
            ]);

            
        }
        return $this->generateErrorReport($duplicateRowsInExcelFile, $duplicateRowsInDb);
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