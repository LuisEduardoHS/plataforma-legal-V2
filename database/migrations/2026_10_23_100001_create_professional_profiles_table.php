<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('headline', 180)->nullable();
            $table->text('description')->nullable();
            $table->text('license_number')->nullable();
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->foreignId('municipality_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('attention_mode', ['in_person', 'remote', 'hybrid'])->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('website', 2048)->nullable();
            $table->boolean('contact_visible')->default(false);
            $table->enum('visibility', ['private', 'public'])->default('private');
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['visibility', 'verification_status']);
        });

        DB::statement("ALTER TABLE professional_profiles ADD CONSTRAINT professional_verification_dates_check CHECK ((verification_status = 'verified' AND verified_at IS NOT NULL) OR (verification_status <> 'verified' AND verified_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_profiles');
    }
};
