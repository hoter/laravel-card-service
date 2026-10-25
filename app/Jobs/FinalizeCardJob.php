<?php

namespace App\Jobs;

use App\Models\CardJob;
use App\Services\Support\TraceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeCardJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $cardJobId, public bool $approve) {}

    public function uniqueId(): string
    {
        return 'finalize-card-'.$this->cardJobId;
    }

    public function handle(): void
    {
        $job = CardJob::find($this->cardJobId);
        if (! $job) {
            return;
        }

        app(TraceContext::class)->jobId = $job->id;
        app(TraceContext::class)->requestId = $job->request_id;
        Log::withContext(['card_job_id' => $job->id, 'request_id' => $job->request_id]);

        DB::transaction(function () use ($job): void {
            $fresh = CardJob::whereKey($job->id)->lockForUpdate()->first();
            if (! $fresh || ! in_array($fresh->status, ['awaiting_approval', 'needs_review'], true)) {
                return;
            }

            $fresh->update($this->approve
                ? ['status' => 'approved', 'error' => null]
                : ['status' => 'rejected', 'error' => 'rejected by human']);
        });
    }
}
