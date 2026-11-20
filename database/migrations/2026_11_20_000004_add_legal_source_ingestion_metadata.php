<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lexa_legal_sources', function (Blueprint $table) {
            $table->string('instrument_key', 100)->nullable();
            $table->string('version_key', 100)->nullable();
            $table->char('document_hash', 64)->nullable();
            $table->json('provenance')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by', 120)->nullable();
            $table->text('verification_note')->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->foreign('superseded_by')->references('id')->on('lexa_legal_sources')->restrictOnDelete();
            $table->unique(['instrument_key', 'version_key'], 'lexa_source_version_unique');
        });
        Schema::table('lexa_legal_chunks', function (Blueprint $table) {
            $table->unsignedInteger('ordinal')->nullable();
            $table->unsignedInteger('part')->nullable();
            $table->unique(['lexa_legal_source_id', 'ordinal'], 'lexa_chunk_ordinal_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('lexa_legal_sources')->whereNotNull('instrument_key')->exists()) {
            throw new RuntimeException('No se puede retirar procedencia de documentos importados.');
        }
        Schema::table('lexa_legal_chunks', function (Blueprint $table) {
            $table->dropUnique('lexa_chunk_ordinal_unique');
            $table->dropColumn(['ordinal', 'part']);
        });
        Schema::table('lexa_legal_sources', function (Blueprint $table) {
            $table->dropForeign(['superseded_by']);
            $table->dropUnique('lexa_source_version_unique');
            $table->dropColumn(['instrument_key', 'version_key', 'document_hash', 'provenance', 'verified_at', 'verified_by', 'verification_note', 'superseded_by']);
        });
    }
};
