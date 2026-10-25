<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Log;

class Reindexer
{
    public function __construct(
        private readonly ChunkRepository $chunks,
        private readonly EmbeddingGateway $embeddings,
    ) {}

    public function forDocument(string $documentId): int
    {
        return $this->run($documentId);
    }

    public function all(): int
    {
        return $this->run(null);
    }

    private function run(?string $documentId): int
    {
        $indexed = 0;

        while (true) {
            $rows = $this->chunks->withoutEmbedding(32, $documentId);
            if ($rows === []) {
                break;
            }

            $vectors = $this->embeddings->embed(array_column($rows, 'text'));

            foreach ($rows as $index => $row) {
                $this->chunks->setEmbedding($row['chunk_id'], $vectors[$index]);
            }

            $indexed += count($rows);
            Log::info('embeddings_indexed', ['batch' => count($rows), 'total' => $indexed]);
        }

        return $indexed;
    }
}
