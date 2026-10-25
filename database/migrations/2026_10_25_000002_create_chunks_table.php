<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('document_id', 32);
            $table->unsignedInteger('ordinal');
            $table->longText('text');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->unique(['document_id', 'ordinal']);
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('ALTER TABLE chunks ADD COLUMN embedding vector(1024)');
        DB::statement("ALTER TABLE chunks ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('russian', text)) STORED");
        DB::statement('CREATE INDEX chunks_embedding_hnsw_idx ON chunks USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX chunks_tsv_gin_idx ON chunks USING gin (tsv)');
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
