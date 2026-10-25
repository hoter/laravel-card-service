<?php

namespace Tests\Feature;

use App\Services\Rag\ChunkRepository;
use App\Services\Rag\ContextBuilder;
use App\Services\Rag\EmbeddingGateway;
use App\Services\Support\Vector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetrievalTest extends TestCase
{
    use RefreshDatabase;

    private const E_BLENDER = [1.0, 0.0];

    private const E_KETTLE = [0.0, 1.0];

    protected function setUp(): void
    {
        parent::setUp();

        $vector = fn (array $values): string => Vector::literal(
            array_merge($values, array_fill(0, 1022, 0.0))
        );

        DB::table('documents')->insert([
            'id' => 'doc1',
            'filename' => 'test.pdf',
            'content_hash' => hash('sha256', 'doc1'),
            'kind' => 'pdf',
            'status' => 'indexed',
        ]);

        DB::statement(
            'INSERT INTO chunks (id, document_id, ordinal, text, metadata, embedding) VALUES
             (?, ?, ?, ?, ?::jsonb, ?::vector),
             (?, ?, ?, ?, ?::jsonb, ?::vector)',
            [
                'c1', 'doc1', 0, 'Мощность блендера 800 Вт', '{"page":1}', $vector(self::E_BLENDER),
                'c2', 'doc1', 1, 'Чайник 2200 Вт', '{"page":2}', $vector(self::E_KETTLE),
            ]
        );

        $embedding = array_merge(self::E_BLENDER, array_fill(0, 1022, 0.0));
        $gateway = $this->mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embedOne')->andReturn($embedding);
        $gateway->shouldReceive('embed')->andReturn([$embedding]);
    }

    public function test_vector_search_returns_nearest(): void
    {
        $rows = app(ChunkRepository::class)->vector('блендер', 3);

        $this->assertSame('c1', $rows[0]['chunk_id']);
        $this->assertGreaterThan(0.99, $rows[0]['score']);
    }

    public function test_fts_search_finds_exact_word(): void
    {
        $rows = app(ChunkRepository::class)->fts('чайник', 5);

        $this->assertContains('c2', array_column($rows, 'chunk_id'));
    }

    public function test_document_filter(): void
    {
        $rows = app(ChunkRepository::class)->vector('блендер', 3, null, ['other']);

        $this->assertSame([], $rows);
    }

    public function test_context_has_identifiable_headers(): void
    {
        $builder = app(ContextBuilder::class);
        $context = $builder->build([[
            'chunk_id' => 'c1',
            'text' => 'Мощность 800 Вт',
            'metadata' => ['page' => 1, 'section' => 'Характеристики'],
            'filename' => 'test.pdf',
        ]]);

        $this->assertStringContainsString('[S1 | c1 | test.pdf | стр. 1 | Характеристики]', $context);
        $this->assertSame(['c1'], $builder->chunkIds($context));
        $this->assertSame(['S1' => 'c1'], $builder->labelMap($context));
    }
}
