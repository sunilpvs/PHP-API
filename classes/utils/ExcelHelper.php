<?php
// this file is to provide helper functions for Excel import operations
// it reads a configuration file 



use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;


class ExcelHelper {

    private $config;

    public function __construct($configFilePath) {
        if (!file_exists($configFilePath)) {
            throw new Exception("Configuration file not found: $configFilePath");
        }
        $this->config = parse_ini_file($configFilePath, true);
    }

    // private $sheetName = $this->config['sheet']['sheet-name'] ?? 'Sheet1';
    
}