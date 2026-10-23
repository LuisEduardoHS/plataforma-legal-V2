<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profile_specialties', function (Blueprint $table) {
            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_specialty_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['professional_profile_id', 'legal_specialty_id'], 'professional_profile_specialties_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_profile_specialties');
    }
};
