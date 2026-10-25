<?php

namespace App\Services\Llm;

final class CostCalculator
{
    private const PER_UNIT = 1_000_000;

    public function forModel(string $model, int $inputTokens, int $outputTokens): int
    {
        $tier = match ($model) {
            config('card.models.aux') => 'aux',
            config('card.models.embedding') => 'embedding',
            default => 'main',
        };

        $prices = config("card.pricing.{$tier}");

        return $this->tokens($inputTokens, (int) $prices['input'])
            + $this->tokens($outputTokens, (int) $prices['output']);
    }

    private function tokens(int $tokens, int $pricePerMillion): int
    {
        if ($tokens <= 0 || $pricePerMillion <= 0) {
            return 0;
        }

        return intdiv($tokens * $pricePerMillion + intdiv(self::PER_UNIT, 2), self::PER_UNIT);
    }
}
