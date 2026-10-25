<?php

namespace App\Services\Documents;

final class Chunker
{
    private const SIZE = 900;

    private const OVERLAP = 180;

    /**
     * @param  array<int, array{content: string, kind: string, rows?: array, page?: int|null, section?: string|null}>  $blocks
     * @return array<int, array{text: string, metadata: array}>
     */
    public function chunk(array $blocks, string $docId): array
    {
        $chunks = [];
        $group = [];

        $flush = function () use (&$group, &$chunks, $docId): void {
            if ($group === []) {
                return;
            }
            $first = $group[0];
            $text = implode("\n", array_column($group, 'content'));
            foreach ($this->split($text) as $piece) {
                $chunks[] = [
                    'text' => $piece,
                    'metadata' => [
                        'doc_id' => $docId,
                        'page' => $first['page'] ?? null,
                        'section' => $first['section'] ?? '',
                    ],
                ];
            }
            $group = [];
        };

        foreach ($blocks as $block) {
            if (($block['kind'] ?? 'text') === 'table') {
                $flush();
                array_push($chunks, ...$this->tableChunks($block, $docId));

                continue;
            }

            if ($group !== []) {
                $previous = $group[0];
                $same = ($previous['page'] ?? null) === ($block['page'] ?? null)
                    && ($previous['section'] ?? '') === ($block['section'] ?? '');
                if (! $same) {
                    $flush();
                }
            }
            $group[] = $block;
        }
        $flush();

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    public function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= self::SIZE) {
            return [$text];
        }

        $sentences = preg_split('~(?<=[.!?])\s+|\R+~u', $text) ?: [$text];
        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if (mb_strlen($sentence) > self::SIZE) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }

                array_push($pieces, ...$this->splitWords($sentence));

                continue;
            }

            $candidate = $current === '' ? $sentence : $current.' '.$sentence;

            if (mb_strlen($candidate) <= self::SIZE) {
                $current = $candidate;

                continue;
            }

            $pieces[] = $current;
            $current = trim($this->tail($current).' '.$sentence);

            if (mb_strlen($current) > self::SIZE) {
                $current = $sentence;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @return array<int, string>
     */
    private function splitWords(string $text): array
    {
        $words = preg_split('~\s+~u', $text) ?: [];
        $pieces = [];
        $current = [];
        $length = 0;

        foreach ($words as $word) {
            $addition = mb_strlen($word) + ($current === [] ? 0 : 1);
            if ($current !== [] && $length + $addition > self::SIZE) {
                $pieces[] = implode(' ', $current);
                $tail = $this->tailWords($current);
                $current = $tail;
                $length = mb_strlen(implode(' ', $tail));
                $addition = mb_strlen($word) + ($current === [] ? 0 : 1);
            }
            $current[] = $word;
            $length += $addition;
        }

        if ($current !== []) {
            $pieces[] = implode(' ', $current);
        }

        return $pieces;
    }

    /**
     * @param  array<int, string>  $words
     * @return array<int, string>
     */
    private function tailWords(array $words): array
    {
        $tail = [];
        $length = 0;

        foreach (array_reverse($words) as $previous) {
            if ($length + mb_strlen($previous) + 1 > self::OVERLAP) {
                break;
            }
            array_unshift($tail, $previous);
            $length += mb_strlen($previous) + 1;
        }

        return $tail;
    }

    private function tail(string $text): string
    {
        return implode(' ', $this->tailWords(preg_split('~\s+~u', $text) ?: []));
    }

    private function tableChunks(array $block, string $docId): array
    {
        $rows = $block['rows'] ?? [];
        if (count($rows) < 2) {
            return [];
        }

        $headers = $rows[0];
        $chunks = [];

        foreach (array_slice($rows, 1) as $row) {
            [$text, $articul] = $this->rowText($headers, $row);
            if ($text === '') {
                continue;
            }
            $metadata = [
                'doc_id' => $docId,
                'page' => $block['page'] ?? null,
                'section' => $block['section'] ?? '',
            ];
            if ($articul !== null) {
                $metadata['articul'] = $articul;
            }
            $chunks[] = ['text' => $text, 'metadata' => $metadata];
        }

        return $chunks;
    }

    private function rowText(array $headers, array $row): array
    {
        if (count($headers) === 2 && in_array(mb_strtolower(trim($headers[0])), ['параметр', 'показатель'], true)) {
            $name = trim($row[0] ?? '');
            $value = trim($row[1] ?? '');

            return [$name !== '' ? $name.': '.$value : $value, null];
        }

        $articul = null;
        $parts = [];
        foreach ($headers as $index => $header) {
            $cell = trim((string) ($row[$index] ?? ''));
            if ($cell === '') {
                continue;
            }
            $name = trim((string) $header);
            if (in_array(mb_strtolower($name), ['артикул', 'sku', 'article', 'код'], true)) {
                $articul = $cell;

                continue;
            }
            $parts[] = $name.' '.$cell;
        }

        return [($articul !== null ? $articul.': ' : '').implode('; ', $parts), $articul];
    }
}
