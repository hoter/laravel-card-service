<?php

namespace App\Services\Rag;

use App\Services\Support\Vector;
use Illuminate\Support\Facades\DB;

class ChunkRepository
{
    public function __construct(private readonly EmbeddingGateway $embeddings) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function vector(string $query, int $topK = 5, ?float $threshold = null, ?array $docIds = null): array
    {
        $threshold ??= (float) config('card.search_min_similarity');
        $vector = Vector::literal($this->embeddings->embedOne($query));
        $filter = $this->docFilter($docIds);

        return $this->rows(
            "SELECT c.id AS chunk_id, c.document_id, c.text, c.metadata, d.filename,
                    1 - (c.embedding <=> ?::vector) AS score
             FROM chunks c JOIN documents d ON d.id = c.document_id
             WHERE c.embedding IS NOT NULL
               AND 1 - (c.embedding <=> ?::vector) >= ?
               {$filter['sql']}
             ORDER BY c.embedding <=> ?::vector
             LIMIT ?",
            [$vector, $vector, $threshold, ...$filter['bindings'], $vector, $topK]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fts(string $query, int $topK = 5, ?array $docIds = null): array
    {
        $filter = $this->docFilter($docIds);

        return $this->rows(
            "SELECT c.id AS chunk_id, c.document_id, c.text, c.metadata, d.filename,
                    ts_rank_cd(c.tsv, plainto_tsquery('russian', ?)) AS score
             FROM chunks c JOIN documents d ON d.id = c.document_id
             WHERE c.tsv @@ plainto_tsquery('russian', ?)
               {$filter['sql']}
             ORDER BY score DESC, c.id
             LIMIT ?",
            [$query, $query, ...$filter['bindings'], $topK]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hybrid(string $query, int $topK = 5, ?float $threshold = null, ?array $docIds = null): array
    {
        $threshold ??= (float) config('card.search_min_similarity');
        $candidates = max($topK * 4, 20);
        $vector = Vector::literal($this->embeddings->embedOne($query));
        $filter = $this->docFilter($docIds);
        $rrfK = 60;

        return $this->rows(
            "WITH vector_candidates AS (
                SELECT c.id, row_number() OVER (ORDER BY c.embedding <=> ?::vector) AS rank
                FROM chunks c
                WHERE c.embedding IS NOT NULL
                  AND 1 - (c.embedding <=> ?::vector) >= ?
                  {$filter['sql']}
                ORDER BY c.embedding <=> ?::vector
                LIMIT ?
             ),
             word_candidates AS (
                SELECT c.id,
                       row_number() OVER (ORDER BY ts_rank_cd(c.tsv, plainto_tsquery('russian', ?)) DESC) AS rank
                FROM chunks c
                WHERE c.tsv @@ plainto_tsquery('russian', ?)
                  {$filter['sql']}
                ORDER BY ts_rank_cd(c.tsv, plainto_tsquery('russian', ?)) DESC
                LIMIT ?
             ),
             fused AS (
                SELECT id, sum(1.0 / ({$rrfK} + rank)) AS score
                FROM (
                    SELECT id, rank FROM vector_candidates
                    UNION ALL
                    SELECT id, rank FROM word_candidates
                ) lists
                GROUP BY id
             )
             SELECT c.id AS chunk_id, c.document_id, c.text, c.metadata, d.filename, f.score
             FROM fused f
             JOIN chunks c ON c.id = f.id
             JOIN documents d ON d.id = c.document_id
             ORDER BY f.score DESC, c.id
             LIMIT ?",
            [
                $vector, $vector, $threshold, ...$filter['bindings'], $vector, $candidates,
                $query, $query, ...$filter['bindings'], $query, $candidates, $topK,
            ]
        );
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->rows(
            "SELECT c.id AS chunk_id, c.document_id, c.text, c.metadata, d.filename
             FROM chunks c JOIN documents d ON d.id = c.document_id
             WHERE c.id IN ({$placeholders})",
            array_values($ids)
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['chunk_id']] = $row;
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return array_map(
            static fn (object $row) => (string) $row->id,
            DB::select("SELECT id FROM chunks WHERE id IN ({$placeholders})", array_values($ids))
        );
    }

    public function setEmbedding(string $chunkId, array $vector): void
    {
        DB::update(
            'UPDATE chunks SET embedding = ?::vector WHERE id = ?',
            [Vector::literal($vector), $chunkId]
        );
    }

    /**
     * @return array<int, array{chunk_id: string, text: string}>
     */
    public function withoutEmbedding(int $limit = 32, ?string $documentId = null): array
    {
        $sql = 'SELECT id AS chunk_id, text FROM chunks WHERE embedding IS NULL';
        $bindings = [];
        if ($documentId !== null) {
            $sql .= ' AND document_id = ?';
            $bindings[] = $documentId;
        }
        $sql .= ' ORDER BY id LIMIT ?';
        $bindings[] = $limit;

        return array_map(
            static fn (object $row) => ['chunk_id' => (string) $row->chunk_id, 'text' => (string) $row->text],
            DB::select($sql, $bindings)
        );
    }

    private function docFilter(?array $docIds): array
    {
        if ($docIds === null || $docIds === []) {
            return ['sql' => '', 'bindings' => []];
        }

        $placeholders = implode(',', array_fill(0, count($docIds), '?'));

        return ['sql' => "AND c.document_id IN ({$placeholders})", 'bindings' => array_values($docIds)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $bindings): array
    {
        return array_map(function (object $row): array {
            $data = (array) $row;
            if (isset($data['metadata']) && is_string($data['metadata'])) {
                $data['metadata'] = json_decode($data['metadata'], true) ?: [];
            }

            return $data;
        }, DB::select($sql, $bindings));
    }
}
