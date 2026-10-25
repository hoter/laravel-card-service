<?php

namespace App\Services\Documents;

use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

final class DocxParser
{
    public function parse(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $bytes);

        try {
            $document = IOFactory::load($path, 'Word2007');
        } catch (\Throwable $exception) {
            @unlink($path);
            throw new ParseException('Не удалось открыть DOCX: '.$exception->getMessage());
        }

        $blocks = [];
        $section = null;

        foreach ($document->getSections() as $docSection) {
            foreach ($docSection->getElements() as $element) {
                if ($element instanceof Title) {
                    $section = trim((string) $element->getText());
                    if ($section !== '') {
                        $blocks[] = ['content' => $section, 'kind' => 'text', 'page' => 1, 'section' => $section];
                    }
                } elseif ($element instanceof TextRun || $element instanceof Text || $element instanceof Link) {
                    $text = trim((string) $element->getText());
                    if ($text !== '') {
                        $blocks[] = ['content' => $text, 'kind' => 'text', 'page' => 1, 'section' => $section];
                    }
                } elseif ($element instanceof Table) {
                    $rows = [];
                    foreach ($element->getRows() as $row) {
                        $cells = [];
                        foreach ($row->getCells() as $cell) {
                            $cells[] = $this->cellText($cell);
                        }
                        if (array_filter($cells, static fn (string $v) => $v !== '') !== []) {
                            $rows[] = $cells;
                        }
                    }
                    if (count($rows) >= 2) {
                        $blocks[] = ['content' => '', 'kind' => 'table', 'rows' => $rows, 'page' => 1, 'section' => $section];
                    }
                }
            }
        }

        @unlink($path);

        if ($blocks === []) {
            throw new ParseException('В DOCX нет текста');
        }

        return $blocks;
    }

    private function cellText($cell): string
    {
        $parts = [];
        foreach ($cell->getElements() as $element) {
            if ($element instanceof Text || $element instanceof TextRun || $element instanceof Link || $element instanceof Title) {
                $parts[] = trim((string) $element->getText());
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }
}
