<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexa_legal_sources', function (Blueprint $table) {
            $table->id();
            $table->string('instrument', 180);
            $table->string('short_name', 40);
            $table->enum('norm_type', ['constitution', 'federal_law', 'state_code', 'regulation', 'jurisprudence']);
            $table->enum('jurisdiction_scope', ['federal', 'state']);
            $table->foreignId('state_id')->nullable()->constrained('states')->restrictOnDelete();
            $table->string('source_name', 120);
            $table->string('source_url', 500);
            $table->date('published_at')->nullable();
            $table->date('last_reformed_at')->nullable();
            $table->date('consulted_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['jurisdiction_scope', 'state_id', 'is_verified'], 'lexa_sources_jurisdiction_idx');
        });

        Schema::create('lexa_legal_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lexa_legal_source_id')->constrained('lexa_legal_sources')->restrictOnDelete();
            $table->string('article_ref', 80);
            $table->string('heading', 200)->nullable();
            $table->text('content');
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->index(['lexa_legal_source_id', 'id'], 'lexa_chunks_source_idx');
            $table->fullText(['article_ref', 'heading', 'content'], 'lexa_legal_chunks_fulltext');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexa_legal_chunks');
        Schema::dropIfExists('lexa_legal_sources');
    }
};
