<?php

namespace Tests\Feature;

use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_accepts_pdf_and_returns_202(): void
    {
        Storage::fake('local');
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('passport.pdf', "%PDF-1.4\nтест");

        $response = $this->postJson('/api/documents', ['file' => $file]);

        $response->assertStatus(202)->assertJsonStructure(['id', 'status']);
        Queue::assertPushed(IndexDocumentJob::class);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_same_content_does_not_create_duplicate(): void
    {
        Storage::fake('local');
        Queue::fake();

        $bytes = "%PDF-1.4\nодинаковый";
        $first = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('a.pdf', $bytes),
        ])->json('id');

        $second = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('b.pdf', $bytes),
        ])->json('id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        Storage::fake('local');

        $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_document_status_is_readable(): void
    {
        $document = Document::create([
            'id' => 'doc1',
            'filename' => 'a.pdf',
            'content_hash' => hash('sha256', 'a'),
            'kind' => 'pdf',
            'status' => 'indexed',
            'chunks_count' => 3,
        ]);

        $this->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('status', 'indexed')
            ->assertJsonPath('chunks_count', 3);
    }
}
