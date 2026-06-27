<?php

// This file is used to handle the generation of Excel Templates for different modules based on the configuration file

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

require_once __DIR__ . '/../DbController.php';
require_once __DIR__ . '/../../vendor/autoload.php';

class ExcelTemplateHelper
{
    private DBController $conn;
    private $config;
    private $sheetName;
    private $module;
    private $templateFileName;
    private $templateColumns = [];
    private $columnQueryMapping = [];

    public function __construct($configFilePath)
    {
        if (!file_exists($configFilePath)) {
            throw new Exception("Configuration file not found: $configFilePath");
        }
        $this->config = parse_ini_file($configFilePath, true);
        $this->conn = new DBController();
        $this->sheetName = $this->config['sheet']['sheet-name'] ?? 'Sheet1';
        $this->templateFileName = $this->config['template']['file'] ?? null;
        $this->templateColumns = $this->config['template-columns'] ?? [];
        $this->columnQueryMapping = $this->config['column-query-mapping'] ?? [];
        $this->module = $this->config['module']['name'] ?? null;
    }

    // function to generate an excel file with the columns
    public function generateTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetName);

        // Header row
        $columnIndex = 1;

        foreach ($this->templateColumns as $column) {

            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . '1';

            $sheet->setCellValue($cell, $column);

            $sheet->getStyle($cell)->getFont()->setBold(true);

            $columnIndex++;
        }

        // Hidden lookup sheet
        $lookupSheet = $spreadsheet->createSheet();
        $lookupSheet->setTitle("LOOKUPS");
        $lookupSheet->setSheetState(
            \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN
        );

        $lookupColumn = 1;

        foreach ($this->columnQueryMapping as $columnName => $query) {

            $rows = $this->conn->runQuery($query);

            if (empty($rows))
                continue;

            $lookupRow = 1;

            foreach ($rows as $row) {
                
                $value = reset($row);

                $lookupSheet->setCellValue(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lookupColumn) . $lookupRow,
                    $value
                );

                $lookupRow++;
            }

            $lookupColumnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lookupColumn);

            $range = "'LOOKUPS'!\$" . $lookupColumnLetter . "\$1:\$" . $lookupColumnLetter . "\$" . ($lookupRow - 1);

            // Find the template column index
            $templateIndex = array_search($columnName, $this->templateColumns);

            if ($templateIndex !== false) {

                $excelColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($templateIndex + 1);

                $validation = $sheet
                    ->getCell($excelColumn . '2')
                    ->getDataValidation();

                $validation->setType(
                    \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST
                );

                $validation->setErrorStyle(
                    \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP
                );

                $validation->setAllowBlank(true);

                $validation->setShowDropDown(true);

                $validation->setFormula1($range);

                // Copy validation for first 1000 rows
                for ($i = 3; $i <= 1000; $i++) {

                    $sheet->getCell($excelColumn . $i)
                        ->setDataValidation(clone $validation);
                }
            }

            $lookupColumn++;
        }

        // Autosize
        foreach (range(1, count($this->templateColumns)) as $i) {

            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);

            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }

        // Download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        header('Content-Disposition: attachment;filename="' . $this->templateFileName . '"');

        header('Cache-Control: max-age=0');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $writer->save('php://output');

        exit;
    }
}
