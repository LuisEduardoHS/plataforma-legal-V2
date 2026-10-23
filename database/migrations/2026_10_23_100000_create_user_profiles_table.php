<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('display_name', 150)->nullable();
            $table->string('username', 30)->nullable()->unique();
            $table->text('biography')->nullable();
            $table->string('website', 2048)->nullable();
            $table->foreignId('municipality_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('photo_attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $table->enum('visibility', ['private', 'public'])->default('private');
            $table->timestamps();
            $table->index(['visibility', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
