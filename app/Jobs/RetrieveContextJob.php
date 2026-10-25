<?php

namespace App\Jobs;

use App\Models\CardJob;
use App\Models\Document;
use App\Services\Rag\RagPipeline;
use App\Services\Support\TraceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetrieveContextJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 420;

    public array $backoff = [10, 30];

    public function __construct(public string $cardJobId) {}

    public function uniqueId(): string
    {
        return 'retrieve-context-'.$this->cardJobId;
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
            if (! $fresh || $fresh->status !== 'pending') {
                return false;
            }
            $fresh->update(['status' => 'retrieving']);

            return true;
        });

        if (! $claimed) {
            return;
        }

        $query = trim((string) $job->hint);
        if ($query === '') {
            $query = Document::whereIn('id', $job->document_ids)
                ->pluck('filename')
                ->map(static fn (string $name) => pathinfo($name, PATHINFO_FILENAME))
                ->implode(' ');
        }

        [$context, $report] = $pipeline->retrieve($query ?: 'товар', $job->document_ids);

        $job->update([
            'context' => $context,
            'guard_report' => $report->toArray(),
        ]);

        if ($context === '' || $report->needsReview()) {
            $reason = $context === ''
                ? 'все найденные фрагменты исключены защитой'
                : 'слишком много подозрительных фрагментов: '.implode(', ', $report->suspiciousChunks);

            $job->update(['status' => 'needs_review', 'error' => $reason]);
            Log::info('card_job_needs_review', ['reason' => $reason]);

            return;
        }

        $job->update(['status' => 'generating']);
        GenerateDraftJob::dispatch($job->id);
    }

    public function failed(?Throwable $exception): void
    {
        CardJob::whereKey($this->cardJobId)->update([
            'status' => 'failed',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 1000) ?: 'не удалось собрать контекст',
        ]);
    }
}
