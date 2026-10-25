<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_jobs', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('status', 32)->default('pending');
            $table->text('hint')->nullable();
            $table->jsonb('document_ids');
            $table->longText('context')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('result')->nullable();
            $table->jsonb('guard_report')->nullable();
            $table->text('error')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_jobs');
    }
};
