<?php

class ExcelImportHelper
{
    public static function findHeaderColumn(array $headerRow, string $expectedHeader): ?string
    {
        foreach ($headerRow as $col => $value) {
            if (strtolower(trim((string)$value)) === strtolower(trim($expectedHeader))) {
                return $col;
            }
        }

        return null;
    }

    public static function analyzeColumnValues(array $rows, string $columnKey, array $options = []): array
    {
        $regex = $options['regex'] ?? null;
        $nullValues = $options['null_values'] ?? ['', 'null'];
        $nullReason = $options['null_reason'] ?? 'Value is empty';
        $invalidReason = $options['invalid_reason'] ?? 'Invalid value';
        $duplicateFileReason = $options['duplicate_file_reason'] ?? 'Duplicate value in file';
        $checkDuplicateFile = ($duplicateFileReason !== null && trim((string)$duplicateFileReason) !== '');
        $normalizer = $options['normalizer'] ?? function ($value) {
            return strtolower(trim((string)$value));
        };

        $nullSet = array_fill_keys(array_map('strtolower', $nullValues), true);

        $errors = [];
        $validRows = [];
        $seen = [];
        $stats = [
            'total_rows' => 0,
            'skipped_null' => 0,
            'skipped_invalid' => 0,
            'skipped_duplicate_file' => 0,
        ];

        foreach ($rows as $index => $row) {
            if ($index === 1) {
                continue;
            }

            $stats['total_rows']++;
            $rawValue = isset($row[$columnKey]) ? trim((string)$row[$columnKey]) : '';
            $rawLower = strtolower($rawValue);
            $normalized = $normalizer($rawValue);

            if ($rawValue === '' || isset($nullSet[$rawLower])) {
                $stats['skipped_null']++;
                $errors[] = [
                    'row' => $index,
                    'value' => $rawValue,
                    'reason' => $nullReason,
                ];
                continue;
            }

            if ($regex && !preg_match($regex, $rawValue)) {
                $stats['skipped_invalid']++;
                $errors[] = [
                    'row' => $index,
                    'value' => $rawValue,
                    'reason' => $invalidReason,
                ];
                continue;
            }

            if ($checkDuplicateFile && isset($seen[$normalized])) {
                $stats['skipped_duplicate_file']++;
                $errors[] = [
                    'row' => $index,
                    'value' => $rawValue,
                    'reason' => $duplicateFileReason,
                ];
                continue;
            }

            $seen[$normalized] = true;
            $validRows[] = [
                'row' => $index,
                'value' => $rawValue,
                'normalized' => $normalized,
            ];
        }

        return [
            'valid_rows' => $validRows,
            'errors' => $errors,
            'stats' => $stats,
        ];
    }

    public static function sortRowErrors(array $errors): array
    {
        usort($errors, function ($a, $b) {
            return ($a['row'] ?? 0) <=> ($b['row'] ?? 0);
        });

        return $errors;
    }
}
