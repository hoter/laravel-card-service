<?php

namespace Tests\Feature;

use App\Data\CardData;
use App\Services\Rag\CitationValidator;
use App\Services\Rag\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CitationValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function seedChunk(): void
    {
        DB::table('documents')->insert([
            'id' => 'doc1',
            'filename' => 'passport.pdf',
            'content_hash' => hash('sha256', 'doc1'),
            'kind' => 'pdf',
            'status' => 'indexed',
        ]);

        DB::statement(
            'INSERT INTO chunks (id, document_id, ordinal, text, metadata) VALUES (?, ?, ?, ?, ?::jsonb)',
            ['c1', 'doc1', 0, 'Мощность 800 Вт. Гарантия 24 месяца.', '{"page":1,"section":"Характеристики"}']
        );
    }

    private function context(): string
    {
        return app(ContextBuilder::class)->build([[
            'chunk_id' => 'c1',
            'text' => 'Мощность 800 Вт. Гарантия 24 месяца.',
            'metadata' => ['page' => 1, 'section' => 'Характеристики'],
            'filename' => 'passport.pdf',
        ]]);
    }

    public function test_card_with_labelled_citation_passes(): void
    {
        $this->seedChunk();

        $card = CardData::fromArray([
            'title' => 'Блендер',
            'description' => 'Погружной блендер.',
            'characteristics' => [
                ['name' => 'Мощность', 'value' => '800 Вт', 'chunk_id' => 'S1', 'page' => 1],
            ],
            'benefits' => ['Удобная ручка'],
            'missing_fields' => [],
            'confidence' => 0.9,
            'seo' => [],
        ]);

        $this->assertSame([], app(CitationValidator::class)->validate($card, $this->context()));

        $enriched = app(CitationValidator::class)->enrich($card, $this->context());
        $this->assertSame('c1', $enriched[0]['chunk_id']);
        $this->assertSame('characteristics.Мощность', $enriched[0]['field']);
    }

    public function test_value_must_be_present_in_cited_chunk(): void
    {
        $this->seedChunk();

        $card = CardData::fromArray([
            'title' => 'Блендер',
            'description' => 'Погружной блендер.',
            'characteristics' => [
                ['name' => 'Мощность', 'value' => '1500 Вт', 'chunk_id' => 'S1', 'page' => 1],
            ],
            'benefits' => ['Удобная ручка'],
            'missing_fields' => [],
            'confidence' => 0.9,
            'seo' => [],
        ]);

        $errors = app(CitationValidator::class)->validate($card, $this->context());

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('не найдено в указанном источнике', $errors[0]);
    }

    public function test_characteristic_without_citation_is_rejected(): void
    {
        $this->seedChunk();

        $card = CardData::fromArray([
            'title' => 'Блендер',
            'description' => 'Погружной блендер.',
            'characteristics' => ['Мощность' => '800 Вт', 'Гарантия' => '24 месяца'],
            'benefits' => ['Удобная ручка'],
            'missing_fields' => [],
            'confidence' => 0.9,
            'seo' => [],
            'sources' => [
                ['field' => 'characteristics.Мощность', 'chunk_id' => 'S1', 'page' => 1],
            ],
        ]);

        $errors = app(CitationValidator::class)->validate($card, $this->context());

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('«Гарантия»', $errors[0]);
    }

    public function test_dictionary_characteristics_require_citations(): void
    {
        $this->expectException(ValidationException::class);

        CardData::fromArray([
            'title' => 'Блендер',
            'description' => 'Погружной блендер.',
            'characteristics' => ['Мощность' => '800 Вт'],
            'benefits' => ['Удобная ручка'],
            'missing_fields' => [],
            'confidence' => 0.9,
            'seo' => [],
        ]);
    }
}
