<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('filename');
            $table->string('content_hash', 64)->unique();
            $table->string('kind', 8);
            $table->string('status', 16)->default('new');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('content_type')->nullable();
            $table->string('stored_path')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
