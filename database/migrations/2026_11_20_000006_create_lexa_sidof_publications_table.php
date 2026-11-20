<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexa_sidof_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cod_nota');
            $table->unsignedBigInteger('cod_diario')->nullable();
            $table->date('published_at');
            $table->string('edition', 10);
            $table->string('section', 60)->nullable();
            $table->string('title', 500);
            $table->string('official_url', 500);
            $table->string('preliminary_type', 40);
            $table->string('classification', 40);
            $table->json('matched_instrument_keys')->nullable();
            $table->string('agency_scope', 180)->nullable();
            $table->string('agency', 180)->nullable();
            $table->string('review_status', 40)->default('pending_review');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('detection_count')->default(1);
            $table->boolean('confirmed_reform')->default(false);
            $table->timestamps();

            $table->unique('cod_nota', 'lexa_sidof_pub_cod_nota_unique');
            $table->index(['published_at', 'classification'], 'lexa_sidof_pub_date_class_idx');
            $table->index(['review_status', 'published_at'], 'lexa_sidof_pub_status_date_idx');
        });

        Schema::create('lexa_sidof_publication_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lexa_sidof_publication_id')
                ->constrained('lexa_sidof_publications')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lexa_sidof_publication_id', 'created_at'], 'lexa_sidof_rev_pub_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexa_sidof_publication_reviews');
        Schema::dropIfExists('lexa_sidof_publications');
    }
};
