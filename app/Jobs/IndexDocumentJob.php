<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Documents\DocumentIngestor;
use App\Services\Support\TraceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IndexDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [10, 30];

    public function __construct(public string $documentId) {}

    public function uniqueId(): string
    {
        return 'index-document-'.$this->documentId;
    }

    public function handle(DocumentIngestor $ingestor): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            return;
        }

        Log::withContext(['document_id' => $document->id]);

        $trace = app(TraceContext::class);
        $trace->jobId = null;
        $trace->requestId = null;

        if (in_array($document->status, ['indexed', 'parsing'], true)) {
            return;
        }

        $ingestor->index($document);
    }
}
