<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_evidences', function (Blueprint $table) {
            $table->unique(['verification_request_id', 'content_sha256'], 'verification_evidence_request_content_unique');
        });
    }

    public function down(): void
    {
        Schema::table('verification_evidences', function (Blueprint $table) {
            $table->dropUnique('verification_evidence_request_content_unique');
        });
    }
};
