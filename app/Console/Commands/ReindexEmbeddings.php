<?php

namespace App\Console\Commands;

use App\Services\Rag\Reindexer;
use Illuminate\Console\Command;

class ReindexEmbeddings extends Command
{
    protected $signature = 'rag:reindex {--document=}';

    protected $description = 'Досчитать эмбеддинги фрагментам без векторов';

    public function handle(Reindexer $reindexer): int
    {
        $count = $this->option('document')
            ? $reindexer->forDocument((string) $this->option('document'))
            : $reindexer->all();

        $this->line($count > 0
            ? "Проиндексировано фрагментов: {$count}"
            : 'Фрагментов без векторов нет');

        return self::SUCCESS;
    }
}
