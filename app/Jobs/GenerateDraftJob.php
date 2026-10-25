<?php

namespace App\Jobs;

use App\Data\CardData;
use App\Models\CardJob;
use App\Services\Guardrails\Guard;
use App\Services\Rag\RagPipeline;
use App\Services\Support\TraceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 420;

    public array $backoff = [10, 30];

    /**
     * @param  array<int, string>  $feedback
     */
    public function __construct(public string $cardJobId, public array $feedback = []) {}

    public function handle(RagPipeline $pipeline, Guard $guard): void
    {
        $job = CardJob::find($this->cardJobId);
        if (! $job) {
            return;
        }

        app(TraceContext::class)->jobId = $job->id;
        app(TraceContext::class)->requestId = $job->request_id;
        Log::withContext(['card_job_id' => $job->id, 'request_id' => $job->request_id]);

        $attempt = DB::transaction(function () use ($job): ?int {
            $fresh = CardJob::whereKey($job->id)->lockForUpdate()->first();
            if (! $fresh || ! in_array($fresh->status, ['generating', 'regenerating'], true)) {
                return null;
            }
            $fresh->update([
                'status' => 'generating',
                'attempts' => $fresh->attempts + 1,
            ]);

            return $fresh->attempts;
        });

        if ($attempt === null) {
            return;
        }

        $card = $pipeline->generate((string) $job->context, $job->hint, $this->feedback);

        [$cleaned, $issues] = $guard->output($card->toArray());
        $card = CardData::fromArray($cleaned);

        if ($issues !== []) {
            if ($attempt < 3) {
                $job->update(['status' => 'regenerating']);
                self::dispatch($job->id, $issues);

                return;
            }
            $job->update([
                'status' => 'needs_review',
                'result' => $card->toArray(),
                'error' => implode('; ', $issues),
            ]);

            return;
        }

        $job->update([
            'status' => 'verifying',
            'result' => $card->toArray(),
        ]);

        VerifySourcesJob::dispatch($job->id);
    }

    public function failed(?Throwable $exception): void
    {
        CardJob::whereKey($this->cardJobId)->update([
            'status' => 'failed',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 1000) ?: 'генерация не удалась',
        ]);
    }
}
