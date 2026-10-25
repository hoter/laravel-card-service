<?php

namespace App\Services\Guardrails;

use App\Data\GuardReport;

class Guard
{
    public function __construct(
        private readonly Pii $pii,
        private readonly InjectionRules $rules,
        private readonly InjectionDetector $detector,
    ) {}

    /**
     * @param  array<int, array{chunk_id: string, text: string, metadata?: array}>  $chunks
     * @return array{0: array<int, array<string, mixed>>, 1: GuardReport}
     */
    public function context(array $chunks): array
    {
        $report = new GuardReport;
        $safe = [];

        foreach ($chunks as $chunk) {
            [$masked, $entities] = $this->pii->mask((string) $chunk['text']);
            $report->maskedPii += count($entities);

            if ($this->rules->reason($masked) !== null) {
                if (! $this->detector->isSuspicious($masked)) {
                    $safe[] = [...$chunk, 'text' => $masked];

                    continue;
                }

                [$redacted, $removed] = $this->rules->redact($masked);

                if ($removed && mb_strlen(trim($redacted)) >= 100) {
                    $report->redactedChunks[] = (string) $chunk['chunk_id'];
                    $safe[] = [...$chunk, 'text' => $redacted];

                    continue;
                }

                $report->suspiciousChunks[] = (string) $chunk['chunk_id'];

                continue;
            }

            $safe[] = [...$chunk, 'text' => $masked];
        }

        return [$safe, $report];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    public function output(array|string $card): array
    {
        $data = is_string($card) ? json_decode($card, true) : $card;

        if (! is_array($data)) {
            return [[], ['карточка не является JSON-объектом']];
        }

        $entities = [];
        $data = $this->maskRecursive($data, $entities);
        $issues = [];

        if ($entities !== []) {
            $kinds = array_values(array_unique(array_column($entities, 'kind')));
            $issues[] = 'в карточке остались персональные данные: '.implode(', ', $kinds);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '';
        $reason = $this->rules->reason($json);
        if ($reason !== null) {
            $issues[] = 'в карточке следы служебных инструкций: '.$reason;
        }

        return [$data, $issues];
    }

    private function maskRecursive(array $data, array &$entities): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskRecursive($value, $entities);
            } elseif (is_string($value)) {
                [$masked, $found] = $this->pii->mask($value);
                $data[$key] = $masked;
                array_push($entities, ...$found);
            }
        }

        return $data;
    }
}
