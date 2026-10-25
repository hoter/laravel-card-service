<?php

namespace App\Jobs;

use App\Data\CardData;
use App\Models\CardJob;
use App\Services\Rag\RagPipeline;
use App\Services\Support\TraceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifySourcesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $cardJobId) {}

    public function uniqueId(): string
    {
        return 'verify-sources-'.$this->cardJobId;
    }

    public function handle(RagPipeline $pipeline): void
    {
        $job = CardJob::find($this->cardJobId);
        if (! $job) {
            return;
        }

        app(TraceContext::class)->jobId = $job->id;
        app(TraceContext::class)->requestId = $job->request_id;
        Log::withContext(['card_job_id' => $job->id, 'request_id' => $job->request_id]);

        $claimed = DB::transaction(function () use ($job): bool {
            $fresh = CardJob::whereKey($job->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'verifying') {
                return false;
            }

            return true;
        });
        if (! $claimed) {
            return;
        }

        $card = CardData::fromArray($job->result ?? []);
        $errors = $pipeline->verify($card, (string) $job->context);

        if ($errors !== []) {
            if ($job->attempts < 3) {
                $job->update(['status' => 'regenerating']);
                GenerateDraftJob::dispatch($job->id, $errors);

                return;
            }

            $job->update([
                'status' => 'failed',
                'error' => 'не удалось подтвердить ссылки: '.implode('; ', $errors),
            ]);

            return;
        }

        $job->update(['status' => 'critiquing']);
        CritiqueDraftJob::dispatch($job->id);
    }

    public function failed(?Throwable $exception): void
    {
        CardJob::whereKey($this->cardJobId)->update([
            'status' => 'failed',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 1000) ?: 'проверка ссылок не отработала',
        ]);
    }
}
