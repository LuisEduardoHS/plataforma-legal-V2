<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['professional_license', 'certificate', 'supporting_document']);
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 100);
            $table->char('attachment_fingerprint', 64);
            $table->timestamps();
            $table->unique(['verification_request_id', 'attachment_id'], 'verification_evidence_request_attachment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_evidences');
    }
};
