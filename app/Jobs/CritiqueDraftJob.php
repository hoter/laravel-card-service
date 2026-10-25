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

class CritiqueDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 420;

    public array $backoff = [10, 30];

    public function __construct(public string $cardJobId) {}

    public function uniqueId(): string
    {
        return 'critique-draft-'.$this->cardJobId;
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

            return $fresh !== null && $fresh->status === 'critiquing';
        });
        if (! $claimed) {
            return;
        }

        $card = CardData::fromArray($job->result ?? []);

        try {
            $report = $pipeline->critique((string) $job->context, $card);
        } catch (Throwable $exception) {
            // Критик — вспомогательный сигнал. Если модель недоступна,
            // карточка всё равно доходит до человека: детерминированные
            // проверки уже пройдены.
            Log::warning('critic_unavailable', ['error' => $exception->getMessage()]);
            $report = ['verdict' => 'unavailable', 'issues' => []];
        }

        $guard = $job->guard_report ?? [];
        $guard['critique'] = $report;

        $lowConfidence = $card->confidence < (float) config('card.confidence_threshold');

        // Слабая локальная модель-критик не должна крутить бесконечный
        // regenerate и не должна блокировать карточку: её замечания лежат
        // в guard_report.critique и видны человеку. Жёстко блокируют только
        // проверки схемы и ссылок.
        $job->update([
            'status' => $lowConfidence ? 'needs_review' : 'awaiting_approval',
            'guard_report' => $guard,
            'error' => $lowConfidence
                ? 'низкая уверенность модели: '.$card->confidence
                : null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        CardJob::whereKey($this->cardJobId)->update([
            'status' => 'failed',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 1000) ?: 'критик не отработал',
        ]);
    }
}
