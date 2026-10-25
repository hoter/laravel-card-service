<?php

namespace App\Services\Documents;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\Reindexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentIngestor
{
    public function __construct(
        private readonly PdfParser $pdf,
        private readonly DocxParser $docx,
        private readonly XlsxParser $xlsx,
        private readonly Normalizer $normalizer,
        private readonly Chunker $chunker,
        private readonly Reindexer $reindexer,
    ) {}

    public function index(Document $document): void
    {
        $document->update(['status' => 'parsing', 'error' => null]);

        try {
            $bytes = Storage::disk('local')->get($document->stored_path);
            $blocks = match ($document->kind) {
                'pdf' => $this->pdf->parse($bytes),
                'docx' => $this->docx->parse($bytes),
                'xlsx' => $this->xlsx->parse($bytes),
                default => throw new ParseException("Формат «{$document->kind}» не поддерживается"),
            };

            $chunks = $this->chunker->chunk($this->normalizer->normalize($blocks), $document->id);
            if ($chunks === []) {
                throw new ParseException('Документ не дал ни одного фрагмента');
            }

            DB::transaction(function () use ($document, $chunks): void {
                Chunk::where('document_id', $document->id)->delete();
                foreach ($chunks as $index => $chunk) {
                    Chunk::create([
                        'id' => (string) Str::ulid()->toBase32(),
                        'document_id' => $document->id,
                        'ordinal' => $index,
                        'text' => $chunk['text'],
                        'metadata' => $chunk['metadata'],
                    ]);
                }
                $document->update(['chunks_count' => count($chunks)]);
            });

            $vectors = $this->reindexer->forDocument($document->id);
            $document->update(['status' => 'indexed']);

            Log::info('document_indexed', [
                'document_id' => $document->id,
                'chunks' => count($chunks),
                'vectors' => $vectors,
            ]);
        } catch (\Throwable $exception) {
            $document->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            Log::warning('document_parse_failed', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
