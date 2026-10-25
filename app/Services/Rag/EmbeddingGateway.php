<?php

namespace App\Services\Rag;

use App\Models\LlmCall;
use App\Services\Llm\CostCalculator;
use App\Services\Support\TraceContext;
use Laravel\Ai\Embeddings;

class EmbeddingGateway
{
    public function __construct(
        private readonly TraceContext $trace,
        private readonly CostCalculator $cost,
    ) {}

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $model = config('card.models.embedding');
        $started = microtime(true);

        $response = Embeddings::for(array_values($texts))
            ->generate(provider: 'ollama', model: $model);

        $duration = (int) ((microtime(true) - $started) * 1000);

        LlmCall::create([
            'card_job_id' => $this->trace->jobId,
            'request_id' => $this->trace->requestId,
            'model' => $model,
            'role' => 'Embedding',
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cost_micros' => $this->cost->forModel($model, $response->usage->inputTokens, $response->usage->outputTokens),
            'duration_ms' => $duration,
        ]);

        return $response->embeddings;
    }

    /**
     * @return array<int, float>
     */
    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0];
    }
}
