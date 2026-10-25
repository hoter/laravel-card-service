<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Documents\DocumentIngestor;
use Illuminate\Console\Command;

class IndexDocuments extends Command
{
    protected $signature = 'documents:index {document?}';

    protected $description = 'Разобрать документ(ы) и сохранить фрагменты';

    public function handle(DocumentIngestor $ingestor): int
    {
        $query = Document::query();
        if ($this->argument('document')) {
            $query->whereKey($this->argument('document'));
        } else {
            $query->whereIn('status', ['new', 'parsing', 'failed']);
        }

        foreach ($query->get() as $document) {
            $this->line("Индексирую {$document->filename}");
            $ingestor->index($document);
            $this->line("  → {$document->fresh()->status}");
        }

        return self::SUCCESS;
    }
}
