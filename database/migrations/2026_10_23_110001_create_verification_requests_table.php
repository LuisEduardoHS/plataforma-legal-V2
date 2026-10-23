<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_profile_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'revoked', 'invalidated'])->default('draft');
            $table->unsignedBigInteger('active_profile_id')->nullable()
                ->storedAs("CASE WHEN status IN ('draft','pending','approved') THEN professional_profile_id ELSE NULL END");
            $table->unique('active_profile_id');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->char('profile_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['professional_profile_id', 'created_at']);
        });
        DB::statement("ALTER TABLE verification_requests ADD CONSTRAINT verification_request_lifecycle_check CHECK (
            (status = 'draft' AND submitted_at IS NULL AND resolved_at IS NULL AND reviewed_by IS NULL AND profile_fingerprint IS NULL)
            OR (status = 'pending' AND submitted_at IS NOT NULL AND resolved_at IS NULL AND reviewed_by IS NULL AND profile_fingerprint IS NOT NULL)
            OR (status IN ('approved','rejected','revoked') AND submitted_at IS NOT NULL AND resolved_at IS NOT NULL AND reviewed_by IS NOT NULL AND profile_fingerprint IS NOT NULL)
            OR (status = 'invalidated' AND submitted_at IS NOT NULL AND resolved_at IS NOT NULL AND profile_fingerprint IS NOT NULL)
        )");
        DB::statement("ALTER TABLE verification_requests ADD CONSTRAINT verification_request_reason_check CHECK (status NOT IN ('rejected','revoked','invalidated') OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
    }
};
