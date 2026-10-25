<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Documents\DocumentIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedDemo extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Загрузить и проиндексировать документы из data/';

    public function handle(DocumentIngestor $ingestor): int
    {
        $files = [
            ...(glob(base_path('data/*.pdf')) ?: []),
            ...(glob(base_path('data/*.docx')) ?: []),
            ...(glob(base_path('data/*.xlsx')) ?: []),
        ];

        if ($files === []) {
            $this->error('В data/ нет файлов pdf, docx или xlsx');

            return self::FAILURE;
        }

        foreach ($files as $path) {
            $bytes = (string) file_get_contents($path);
            $hash = hash('sha256', $bytes);
            $filename = basename($path);
            $kind = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            $document = Document::where('content_hash', $hash)->first();

            if ($document === null) {
                $storedPath = Storage::disk('local')->put("documents/{$hash}.{$kind}", $bytes);

                $document = Document::create([
                    'id' => (string) Str::ulid()->toBase32(),
                    'filename' => $filename,
                    'content_hash' => $hash,
                    'kind' => $kind,
                    'status' => 'new',
                    'size_bytes' => strlen($bytes),
                    'content_type' => mime_content_type($path) ?: null,
                    'stored_path' => $storedPath,
                ]);
            }

            $this->line("Индексирую {$filename}");
            $ingestor->index($document);
        }

        $this->newLine();
        $this->table(
            ['документ', 'статус', 'чанков', 'ошибка'],
            Document::orderBy('filename')->get()->map(fn (Document $document) => [
                $document->filename,
                $document->status,
                $document->chunks_count,
                $document->error,
            ])->all()
        );

        return self::SUCCESS;
    }
}
