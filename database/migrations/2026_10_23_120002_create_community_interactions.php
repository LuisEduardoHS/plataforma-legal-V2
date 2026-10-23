<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('like');
            $table->timestamps();
            $table->unique(['post_id', 'user_id', 'type']);
            $table->index(['user_id', 'post_id']);
        });
        Schema::create('user_follows', function (Blueprint $table) {
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['follower_id', 'followed_id']);
            $table->index(['followed_id', 'follower_id']);
        });
        DB::statement('ALTER TABLE user_follows ADD CONSTRAINT user_follows_not_self_check CHECK (follower_id <> followed_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_follows');
        Schema::dropIfExists('post_reactions');
    }
};
