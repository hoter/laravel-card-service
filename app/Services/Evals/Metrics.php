<?php

namespace App\Services\Evals;

final class Metrics
{
    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('~(?<=\d),(?=\d)~u', '.', $value) ?? $value;
        $value = preg_replace('~\s+~u', ' ', $value) ?? $value;

        return trim($value, " .,;:!?\"'«»()[]");
    }

    public function characteristicMatch(array $expected, array $actual): array
    {
        if ($expected === []) {
            return ['score' => 1.0, 'matched' => [], 'missed' => []];
        }

        $matched = [];
        $missed = [];
        foreach ($expected as $name => $value) {
            if (isset($actual[$name]) && $this->normalize((string) $actual[$name]) === $this->normalize((string) $value)) {
                $matched[] = $name;
            } else {
                $missed[] = $name;
            }
        }

        return ['score' => count($matched) / count($expected), 'matched' => $matched, 'missed' => $missed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources  обогащённые sources (page, text)
     */
    public function citationSupport(array $probes, array $sources): array
    {
        if ($probes === []) {
            return ['score' => 1.0, 'checked' => 0, 'failed' => []];
        }

        $failed = [];
        foreach ($probes as $field => $probe) {
            $expectedText = $this->normalize((string) ($probe['text_contains'] ?? ''));
            $expectedPage = $probe['page'] ?? null;
            $ok = false;

            foreach ($sources as $source) {
                if ($expectedPage !== null && (int) ($source['page'] ?? 0) !== (int) $expectedPage) {
                    continue;
                }
                if ($expectedText !== '' && ! str_contains($this->normalize((string) ($source['text'] ?? '')), $expectedText)) {
                    continue;
                }
                $ok = true;
                break;
            }

            if (! $ok) {
                $failed[] = "{$field}: источник не содержит ожидаемое значение";
            }
        }

        return ['score' => (count($probes) - count($failed)) / count($probes), 'checked' => count($probes), 'failed' => $failed];
    }
}
