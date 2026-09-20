<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

require_once __DIR__ . '/../DbController.php';
require_once __DIR__ . '/../../vendor/autoload.php';

class ExportExcelHelper
{
	private DBController $conn;
	private string $query;
	private array $params;
	private string $fileName;

	public function __construct(string $fileName = 'export.xlsx')
	{
		$this->conn = new DBController();
		$this->params = [];
		$this->fileName = $this->normalizeFileName($fileName);
	}

	public function generateExport($query): void
	{
		$rows = $this->conn->runQuery($query, $this->params);
		if (empty($rows)) {
			throw new Exception('No data available to export.');
		}

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Export');

		$headers = array_keys($rows[0]);
		$sheet->fromArray($headers, null, 'A1');
		$sheet->fromArray(array_map(fn($row) => array_values($row), $rows), null, 'A2');
		$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

		foreach (range(1, count($headers)) as $columnIndex) {
			$column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
			$sheet->getColumnDimension($column)->setAutoSize(true);
		}

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Access-Control-Expose-Headers: Content-Disposition');
		header('Content-Disposition: attachment;filename="' . $this->fileName . '"');
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: max-age=0');
		header('Cache-Control: public');

		$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
		$writer->save('php://output');
		$spreadsheet->disconnectWorksheets();

		exit;
	}

	private function normalizeFileName(string $fileName): string
	{
		$fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: 'export';
		return str_ends_with(strtolower($fileName), '.xlsx') ? $fileName : $fileName . '.xlsx';
	}
}
