<?php

namespace App\Http\Controllers\Api;

use App\Data\CardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateCardRequest;
use App\Jobs\FinalizeCardJob;
use App\Jobs\RetrieveContextJob;
use App\Models\CardJob;
use App\Models\Document;
use App\Services\Rag\CitationValidator;
use App\Services\Support\TraceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CardController extends Controller
{
    public function store(GenerateCardRequest $request): JsonResponse
    {
        $documents = Document::whereIn('id', $request->input('document_ids'))->get();

        if ($documents->isEmpty()) {
            return response()->json(['message' => 'документы не найдены'], 404);
        }

        if (! $documents->contains('status', 'indexed')) {
            return response()->json(['message' => 'среди выбранных документов нет проиндексированных'], 400);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey) {
            $existing = CardJob::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return response()->json(['id' => $existing->id, 'status' => $existing->status], 202);
            }
        }

        $job = CardJob::create([
            'id' => (string) Str::ulid()->toBase32(),
            'status' => 'pending',
            'hint' => $request->input('hint'),
            'document_ids' => $request->input('document_ids'),
            'request_id' => app(TraceContext::class)->requestId,
            'idempotency_key' => $idempotencyKey,
        ]);

        Log::withContext(['card_job_id' => $job->id]);
        Log::info('card_job_created');

        RetrieveContextJob::dispatch($job->id);

        return response()->json(['id' => $job->id, 'status' => 'pending'], 202);
    }

    public function show(string $id, CitationValidator $citations): JsonResponse
    {
        $job = CardJob::find($id);

        if (! $job) {
            return response()->json(['message' => 'card job not found'], 404);
        }

        $result = $job->result;
        if ($result !== null) {
            $result['sources'] = $citations->enrich(CardData::fromArray($result), $job->context);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'attempts' => $job->attempts,
            'result' => $result,
            'guard_report' => $job->guard_report,
            'error' => $job->error,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
        ]);
    }

    public function approve(string $id): JsonResponse
    {
        return $this->finalize($id, true);
    }

    public function requestChanges(string $id): JsonResponse
    {
        return $this->finalize($id, false);
    }

    private function finalize(string $id, bool $approve): JsonResponse
    {
        $job = CardJob::find($id);

        if (! $job) {
            return response()->json(['message' => 'card job not found'], 404);
        }

        if (! in_array($job->status, ['awaiting_approval', 'needs_review'], true)) {
            return response()->json([
                'message' => 'задача не ждёт подтверждения',
                'status' => $job->status,
            ], 409);
        }

        FinalizeCardJob::dispatch($job->id, $approve);

        return response()->json([
            'id' => $job->id,
            'signaled' => $approve ? 'approve' : 'request_changes',
        ], 202);
    }
}
