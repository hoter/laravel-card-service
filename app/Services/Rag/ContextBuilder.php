<?php

namespace App\Services\Rag;

final class ContextBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $chunks
     */
    public function build(array $chunks, ?int $maxChars = null): string
    {
        $limit = $maxChars ?? (int) config('card.rag.context_max_chars');
        $seen = [];
        $blocks = [];
        $total = 0;
        $label = 0;

        foreach ($chunks as $chunk) {
            $text = trim((string) ($chunk['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $key = preg_replace('~\s+~u', ' ', $text) ?: $text;
            if (isset($seen[$key])) {
                continue;
            }

            $metadata = $chunk['metadata'] ?? [];
            $label++;
            $header = sprintf(
                '[S%d | %s | %s | стр. %s | %s]',
                $label,
                $chunk['chunk_id'] ?? '',
                $chunk['filename'] ?? '',
                $metadata['page'] ?? '',
                $metadata['section'] ?? ''
            );
            $block = $header."\n".$text;
            $remaining = $limit - $total;

            if ($remaining <= 0) {
                break;
            }
            if (mb_strlen($block) > $remaining) {
                $block = rtrim(mb_substr($block, 0, max($remaining - 3, 0))).'...';
            }

            $seen[$key] = true;
            $blocks[] = $block;
            $total += mb_strlen($block) + 2;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @return array<int, string>
     */
    public function chunkIds(string $context): array
    {
        $ids = [];
        foreach ($this->headers($context) as $header) {
            $ids[] = $header['chunk_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, string>
     */
    public function labelMap(string $context): array
    {
        $map = [];
        foreach ($this->headers($context) as $header) {
            $map[$header['label']] = $header['chunk_id'];
        }

        return $map;
    }

    /**
     * @return array<int, array{label: string, chunk_id: string}>
     */
    private function headers(string $context): array
    {
        $headers = [];
        foreach (preg_split('~\R~u', $context) ?: [] as $line) {
            if (preg_match('~^\[(S\d+)\s*\|\s*([^|\]\s]+)~u', trim($line), $matches)) {
                $headers[] = ['label' => $matches[1], 'chunk_id' => $matches[2]];
            }
        }

        return $headers;
    }
}
