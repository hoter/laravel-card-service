<?php

namespace Tests\Feature;

use App\Jobs\FinalizeCardJob;
use App\Jobs\RetrieveContextJob;
use App\Models\CardJob;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $status = 'indexed'): Document
    {
        return Document::create([
            'id' => 'doc'.uniqid(),
            'filename' => 'spec.pdf',
            'content_hash' => hash('sha256', uniqid('', true)),
            'kind' => 'pdf',
            'status' => $status,
        ]);
    }

    public function test_generation_returns_202_and_creates_job(): void
    {
        Queue::fake();
        $document = $this->document();

        $response = $this->postJson('/api/cards', [
            'document_ids' => [$document->id],
            'hint' => 'блендер',
        ]);

        $response->assertStatus(202)->assertJsonStructure(['id', 'status']);
        Queue::assertPushed(RetrieveContextJob::class);
        $this->assertDatabaseCount('card_jobs', 1);
    }

    public function test_idempotency_key_returns_same_job(): void
    {
        Queue::fake();
        $document = $this->document();

        $first = $this->postJson('/api/cards', [
            'document_ids' => [$document->id],
            'hint' => 'блендер',
        ], ['Idempotency-Key' => 'key-1'])->json('id');

        $second = $this->postJson('/api/cards', [
            'document_ids' => [$document->id],
            'hint' => 'другой',
        ], ['Idempotency-Key' => 'key-1'])->json('id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('card_jobs', 1);
    }

    public function test_no_indexed_documents_is_rejected(): void
    {
        Queue::fake();
        $document = $this->document('failed');

        $this->postJson('/api/cards', ['document_ids' => [$document->id]])
            ->assertStatus(400);
    }

    public function test_approve_dispatches_finalize(): void
    {
        Queue::fake();
        $job = CardJob::create([
            'id' => 'job1',
            'status' => 'awaiting_approval',
            'document_ids' => [],
            'result' => ['title' => 'Товар'],
        ]);

        $this->postJson('/api/cards/'.$job->id.'/approve')
            ->assertStatus(202);

        Queue::assertPushed(FinalizeCardJob::class);
    }

    public function test_approve_is_rejected_in_wrong_status(): void
    {
        Queue::fake();
        $job = CardJob::create([
            'id' => 'job2',
            'status' => 'generating',
            'document_ids' => [],
        ]);

        $this->postJson('/api/cards/'.$job->id.'/approve')
            ->assertStatus(409);
    }
}
