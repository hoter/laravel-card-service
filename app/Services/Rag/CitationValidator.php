<?php

namespace App\Services\Rag;

use App\Data\CardData;
use App\Services\Guardrails\InjectionRules;
use App\Services\Guardrails\Pii;

class CitationValidator
{
    public function __construct(
        private readonly ChunkRepository $chunks,
        private readonly ContextBuilder $context,
        private readonly Pii $pii,
        private readonly InjectionRules $rules,
    ) {}

    /**
     * @return array<int, string>
     */
    public function validate(CardData $card, string $context): array
    {
        $errors = [];

        if ($card->sources === []) {
            return ['в карточке нет ни одной ссылки на источник'];
        }

        $labels = $this->context->labelMap($context);
        $contextIds = $this->context->chunkIds($context);
        $resolved = [];

        foreach ($card->sources as $index => $source) {
            $resolved[$index] = $labels[$source['chunk_id']] ?? $source['chunk_id'];
        }

        $cited = array_values(array_unique($resolved));
        $existing = $this->chunks->existingIds($cited);
        $chunks = $this->chunks->byIds($cited);

        foreach ($card->sources as $index => $source) {
            $chunkId = $resolved[$index];

            if (! in_array($chunkId, $contextIds, true)) {
                $errors[] = in_array($chunkId, $existing, true)
                    ? "источник {$chunkId} существует, но не был в контексте"
                    : "источника {$chunkId} нет в базе";
            }
        }

        $byName = [];
        foreach ($card->characteristics as $name => $value) {
            $byName[$this->normalizeName((string) $name)] = (string) $value;
        }

        $covered = [];
        foreach ($card->sources as $index => $source) {
            $name = $this->characteristicName((string) $source['field']);
            if ($name === null) {
                continue;
            }

            $key = $this->normalizeName($name);
            if (! isset($byName[$key])) {
                $errors[] = "ссылка указывает на несуществующую характеристику: {$name}";

                continue;
            }

            $covered[$key] = true;
            $text = $this->normalizeText((string) ($chunks[$resolved[$index]]['text'] ?? ''));

            if ($text === '' || preg_match('~\[[A-Z]+_\d+\]~u', $byName[$key])) {
                continue;
            }

            if (! str_contains($text, $this->normalizeText($byName[$key]))) {
                $errors[] = "значение характеристики «{$name}» не найдено в указанном источнике";
            }
        }

        foreach ($card->characteristics as $name => $value) {
            if (! isset($covered[$this->normalizeName((string) $name)])) {
                $errors[] = "у характеристики «{$name}» нет ссылки на источник";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enrich(CardData $card, ?string $context = null): array
    {
        $labels = $context === null ? [] : $this->context->labelMap($context);

        $ids = array_map(
            static fn (array $source) => $labels[$source['chunk_id']] ?? $source['chunk_id'],
            $card->sources
        );
        $chunks = $this->chunks->byIds($ids);

        return array_map(function (array $source) use ($chunks, $labels): array {
            $chunkId = $labels[$source['chunk_id']] ?? $source['chunk_id'];
            $chunk = $chunks[$chunkId] ?? null;
            $metadata = $chunk['metadata'] ?? [];
            $text = (string) ($chunk['text'] ?? '');
            $text = $this->pii->mask($text)[0];
            $text = $this->rules->redact($text)[0];

            return [
                ...$source,
                'chunk_id' => $chunkId,
                'document' => $chunk['filename'] ?? null,
                'page' => $metadata['page'] ?? $source['page'],
                'section' => $metadata['section'] ?? null,
                'text' => $text,
            ];
        }, $card->sources);
    }

    private function characteristicName(string $field): ?string
    {
        $field = trim($field);

        foreach (['characteristics.', 'характеристики.', 'characteristic.'] as $prefix) {
            if (str_starts_with(mb_strtolower($field), $prefix)) {
                return trim(mb_substr($field, mb_strlen($prefix)));
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        return $this->normalizeText($name);
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('~(?<=\d),(?=\d)~u', '.', $text) ?? $text;
        $text = preg_replace('~[«»"\'`]~u', '', $text) ?? $text;

        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }
}
