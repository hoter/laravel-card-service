<?php

namespace App\Services\Documents;

final class Normalizer
{
    private const LIGATURES = ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', 'ﬅ' => 'ft', 'ﬆ' => 'st'];

    public function clean(string $text): string
    {
        $text = strtr($text, self::LIGATURES);
        $text = str_replace("\u{00AD}", '', $text);
        $text = preg_replace('~(?<=[A-Za-zА-Яа-яЁё])-\s*\n\s*(?=[A-Za-zА-Яа-яЁё])~u', '', $text) ?? $text;
        $text = preg_replace('~[ \t\x{00A0}]+~u', ' ', $text) ?? $text;
        $lines = array_map('trim', preg_split('~\R~u', $text) ?: []);
        $lines = array_values(array_filter($lines, static fn (string $line) => $line !== ''));

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{content: string, kind: string, rows?: array, page?: int|null, section?: string|null}>  $blocks
     * @return array<int, array{content: string, kind: string, rows?: array, page?: int|null, section?: string|null}>
     */
    public function normalize(array $blocks): array
    {
        $pages = [];
        foreach ($blocks as $block) {
            if (($block['kind'] ?? 'text') === 'text' && isset($block['page'])) {
                $pages[(int) $block['page']] = true;
            }
        }

        $repeated = [];
        if (count($pages) >= 2) {
            $counts = [];
            foreach ($blocks as $block) {
                if (($block['kind'] ?? 'text') !== 'text') {
                    continue;
                }
                foreach ($this->lines($block['content']) as $line) {
                    $key = $this->lineKey($line);
                    if ($key !== null) {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
            $repeated = array_keys(array_filter($counts, static fn (int $count) => $count === count($pages)));
        }

        $result = [];
        foreach ($blocks as $block) {
            if (($block['kind'] ?? 'text') === 'table') {
                $rows = array_map(
                    fn (array $row) => array_map(fn (string $cell) => $this->clean($cell), $row),
                    $block['rows'] ?? []
                );
                $block['rows'] = $rows;
                $block['content'] = implode("\n", array_map(static fn (array $row) => implode(' | ', $row), $rows));
                $result[] = $block;

                continue;
            }

            $kept = array_values(array_filter(
                $this->lines($this->clean($block['content'])),
                fn (string $line) => ! in_array($this->lineKey($line), $repeated, true)
            ));

            if ($kept === []) {
                continue;
            }

            $block['content'] = implode("\n", $kept);
            $block['section'] = $block['section'] ?? $this->deriveSection($block['content']);
            $result[] = $block;
        }

        return $result;
    }

    private function lines(string $text): array
    {
        $lines = array_map('trim', preg_split('~\R~u', $text) ?: []);

        return array_values(array_filter($lines, static fn (string $line) => $line !== ''));
    }

    private function lineKey(string $line): ?string
    {
        if ($line === '' || mb_strlen($line) > 120) {
            return null;
        }

        return (string) preg_replace('~\d+~u', '#', mb_strtolower($line));
    }

    private function deriveSection(string $text): string
    {
        foreach ($this->lines($text) as $line) {
            $lower = mb_strtolower($line);
            if (str_contains($line, '@') || str_contains($lower, 'www.') || ctype_digit($line)) {
                continue;
            }

            return mb_substr($line, 0, 80);
        }

        return '';
    }
}
