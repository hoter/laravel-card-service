<?php

namespace App\Services\Documents;

use Smalot\PdfParser\Parser;

final class PdfParser
{
    /**
     * @return array<int, array{content: string, kind: string, page: int, section: null}>
     */
    public function parse(string $bytes): array
    {
        try {
            $pdf = (new Parser)->parseContent($bytes);
        } catch (\Throwable $exception) {
            throw new ParseException('Не удалось открыть PDF: '.$exception->getMessage());
        }

        $blocks = [];
        foreach ($pdf->getPages() as $index => $page) {
            $text = trim($page->getText());
            if ($text !== '') {
                $blocks[] = [
                    'content' => $text,
                    'kind' => 'text',
                    'page' => $index + 1,
                    'section' => null,
                ];
            }
        }

        if ($blocks === []) {
            throw new ParseException(
                'В PDF нет текстового слоя: похоже на скан, распознавание (OCR) в базовый путь не входит'
            );
        }

        return $blocks;
    }
}
