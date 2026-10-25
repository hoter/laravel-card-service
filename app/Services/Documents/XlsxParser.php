<?php

namespace App\Services\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class XlsxParser
{
    public function parse(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);

        try {
            $book = IOFactory::load($path);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw new ParseException('Не удалось открыть XLSX: '.$exception->getMessage());
        }

        @unlink($path);

        $blocks = [];
        foreach ($book->getAllSheets() as $index => $sheet) {
            $rows = [];
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_map(static fn ($value) => trim((string) $value), $row);
                if (array_filter($cells, static fn (string $v) => $v !== '') !== []) {
                    $rows[] = $cells;
                }
            }

            if (count($rows) < 2) {
                continue;
            }
            if (array_filter($rows[0], static fn (string $v) => $v !== '') === []) {
                throw new ParseException('Лист «'.$sheet->getTitle().'» без заголовков колонок');
            }

            $blocks[] = [
                'content' => '',
                'kind' => 'table',
                'rows' => $rows,
                'page' => $index + 1,
                'section' => $sheet->getTitle(),
            ];
        }

        if ($blocks === []) {
            throw new ParseException('В XLSX нет строк со значениями');
        }

        return $blocks;
    }
}
