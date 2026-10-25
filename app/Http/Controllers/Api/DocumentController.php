<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $bytes = file_get_contents($file->getRealPath());
        $hash = hash('sha256', $bytes);
        $extension = strtolower($file->getClientOriginalExtension());

        $existing = Document::where('content_hash', $hash)->first();
        if ($existing) {
            return response()->json(['id' => $existing->id, 'status' => $existing->status], 202);
        }

        $id = (string) Str::ulid()->toBase32();
        $path = Storage::disk('local')->putFileAs('documents', $file, $hash.'.'.$extension);

        $document = Document::create([
            'id' => $id,
            'filename' => $file->getClientOriginalName(),
            'content_hash' => $hash,
            'kind' => $extension,
            'status' => 'new',
            'size_bytes' => strlen($bytes),
            'content_type' => $file->getClientMimeType(),
            'stored_path' => $path,
        ]);

        IndexDocumentJob::dispatch($document->id);

        return response()->json(['id' => $document->id, 'status' => 'new'], 202);
    }

    public function show(string $id): JsonResponse
    {
        $document = Document::find($id);

        if (! $document) {
            return response()->json(['message' => 'document not found'], 404);
        }

        return response()->json([
            'id' => $document->id,
            'filename' => $document->filename,
            'kind' => $document->kind,
            'status' => $document->status,
            'chunks_count' => $document->chunks_count,
            'error' => $document->error,
            'created_at' => $document->created_at,
        ]);
    }
}
