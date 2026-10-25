<?php

namespace App\Services\Llm;

use App\Models\LlmCall;
use App\Services\Support\TraceContext;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class LlmGateway
{
    public function __construct(
        private readonly TraceContext $trace,
        private readonly CostCalculator $cost,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function structured(
        Agent&HasStructuredOutput $agent,
        string $prompt,
        string $role,
        bool $aux = false,
    ): array {
        $model = $aux ? config('card.models.aux') : config('card.models.main');
        $started = microtime(true);
        $attempt = 0;
        $maxAttempts = (int) config('card.ai_max_retries');

        while (true) {
            $attempt++;

            try {
                $response = $agent->prompt(
                    $prompt,
                    provider: 'ollama',
                    model: $model,
                    timeout: (int) config('card.ai_timeout'),
                );
                break;
            } catch (Throwable $exception) {
                if ($attempt >= $maxAttempts || ! $this->retryable($exception)) {
                    throw $exception;
                }

                $delay = min(2 ** $attempt * 500_000, 8_000_000) + random_int(0, 250_000);
                usleep($delay);
            }
        }

        $duration = (int) ((microtime(true) - $started) * 1000);
        $usage = $response->usage;

        LlmCall::create([
            'card_job_id' => $this->trace->jobId,
            'request_id' => $this->trace->requestId,
            'model' => $model,
            'role' => $role,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_micros' => $this->cost->forModel($model, $usage->inputTokens, $usage->outputTokens),
            'duration_ms' => $duration,
        ]);

        if ($response instanceof StructuredAgentResponse) {
            return $response->structured;
        }

        $decoded = json_decode($response->text, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Модель вернула не JSON: '.mb_substr($response->text, 0, 200));
        }

        return $decoded;
    }

    private function retryable(Throwable $exception): bool
    {
        return $exception instanceof RateLimitedException
            || $exception instanceof ProviderOverloadedException
            || $exception instanceof ProviderConnectionException
            || $exception instanceof ConnectionException;
    }
}
