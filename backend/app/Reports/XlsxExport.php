<?php

namespace App\Reports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a one-sheet .xlsx download: a header row, then the data rows (issue 29, reused by 52-54). Every cell is
 * written as text, so dates stay Y-m-d and IDs keep leading zeros; a null value leaves the cell empty. Callers format
 * values (dates, Yes/No) before passing them in.
 */
final class XlsxExport
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $rowNumber = 1;
        foreach ([$header, ...$rows] as $values) {
            foreach (array_values($values) as $index => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit([$index + 1, $rowNumber], $value, DataType::TYPE_STRING);
                }
            }
            $rowNumber++;
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
