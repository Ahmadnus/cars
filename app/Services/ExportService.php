<?php

namespace App\Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV and Excel writers.
 *
 * Both stream rather than building the whole file in memory, so a large export
 * does not depend on how much data the branch happens to have. The CSV is
 * written with a UTF-8 BOM because Excel on Windows otherwise mangles Arabic.
 */
class ExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function csv(array $headers, array $rows, string $filename): StreamedResponse
    {
        return response()->stream(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');

            // BOM: without it Excel reads the file as ANSI and Arabic breaks.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => $this->scalar($v), $row));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function xlsx(string $sheetTitle, array $headers, array $rows, string $filename): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr($sheetTitle, 0, 31));
        $writer->getCurrentSheet()->setSheetView(
            (new \OpenSpout\Writer\XLSX\Entity\SheetView)->withRightToLeft(true)
        );

        // OpenSpout 5 styles are immutable: with*() returns a new instance.
        $headerStyle = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle($headers, $headerStyle));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $this->scalar($v), $row)));
        }

        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Coerce a value into something a spreadsheet cell can hold. */
    protected function scalar(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'نعم' : 'لا',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
