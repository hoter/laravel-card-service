<?php

namespace App\Services\Guardrails;

final class Pii
{
    private const PHONE = '~(?<![\d+])(?:\+7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)~u';

    private const EMAIL = '~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~u';

    private const CARD = '~(?<!\d)(?:\d[ \-]?){15}\d(?!\d)~u';

    private const INN = '~(?<!\d)(?:\d{12}|\d{10})(?!\d)~u';

    /**
     * @return array{0: string, 1: array<int, array{kind: string, placeholder: string}>}
     */
    public function mask(string $text): array
    {
        $candidates = [];

        $this->collect($candidates, $text, self::EMAIL, 'email', 'EMAIL', static fn () => true);
        $this->collect($candidates, $text, self::CARD, 'card', 'CARD', fn (string $v) => $this->luhnValid($v));
        $this->collect($candidates, $text, self::INN, 'inn', 'INN', fn (string $v) => $this->innValid($v));
        $this->collect($candidates, $text, self::PHONE, 'phone', 'PHONE', static fn () => true);

        usort($candidates, static fn (array $a, array $b) => [$a['start'], -$a['length']] <=> [$b['start'], -$b['length']]);

        $selected = [];
        $occupiedUntil = -1;
        foreach ($candidates as $candidate) {
            if ($candidate['start'] < $occupiedUntil) {
                continue;
            }
            $selected[] = $candidate;
            $occupiedUntil = $candidate['end'];
        }

        $counters = [];
        $entities = [];
        $masked = '';
        $position = 0;

        foreach ($selected as $candidate) {
            $masked .= substr($text, $position, $candidate['start'] - $position);
            $counters[$candidate['label']] = ($counters[$candidate['label']] ?? 0) + 1;
            $placeholder = '['.$candidate['label'].'_'.$counters[$candidate['label']].']';
            $masked .= $placeholder;
            $entities[] = ['kind' => $candidate['kind'], 'placeholder' => $placeholder];
            $position = $candidate['end'];
        }

        $masked .= substr($text, $position);

        return [$masked, $entities];
    }

    public function contains(string $text): bool
    {
        return $this->mask($text)[1] !== [];
    }

    public function innValid(string $value): bool
    {
        $digits = preg_replace('~\D~', '', $value) ?? '';

        if (strlen($digits) === 10) {
            return $this->weighted($digits, [2, 4, 10, 3, 5, 9, 4, 6, 8], 9);
        }

        if (strlen($digits) === 12) {
            return $this->weighted($digits, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8], 10)
                && $this->weighted($digits, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8], 11);
        }

        return false;
    }

    public function luhnValid(string $value): bool
    {
        $digits = preg_replace('~\D~', '', $value) ?? '';

        if (strlen($digits) !== 16) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $index => $char) {
            $digit = (int) $char;
            if ($index % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function weighted(string $digits, array $weights, int $controlIndex): bool
    {
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        return $sum % 11 % 10 === (int) $digits[$controlIndex];
    }

    private function collect(array &$candidates, string $text, string $pattern, string $kind, string $label, callable $valid): void
    {
        if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as [$value, $offset]) {
            if (! $valid($value)) {
                continue;
            }

            $candidates[] = [
                'start' => $offset,
                'end' => $offset + strlen($value),
                'length' => strlen($value),
                'kind' => $kind,
                'label' => $label,
            ];
        }
    }
}
