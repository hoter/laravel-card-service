<?php

namespace App\Data;

class GuardReport
{
    public int $maskedPii = 0;

    public array $suspiciousChunks = [];

    public array $redactedChunks = [];

    public function needsReview(): bool
    {
        return count($this->suspiciousChunks) > (int) config('card.guardrails.suspicious_chunk_limit');
    }

    public function toArray(): array
    {
        return [
            'masked_pii' => $this->maskedPii,
            'suspicious_chunks' => $this->suspiciousChunks,
            'redacted_chunks' => $this->redactedChunks,
            'needs_review' => $this->needsReview(),
        ];
    }
}
