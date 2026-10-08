<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan CSV yang langsung terbaca rapi di Excel berbahasa Indonesia:
 * pemisah titik koma dan BOM UTF-8 supaya kolom dan huruf tidak berantakan.
 */
class CsvExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    public function download(string $fileName, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ';', escape: '');

            foreach ($rows as $row) {
                fputcsv($output, $row, ';', escape: '');
            }

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
