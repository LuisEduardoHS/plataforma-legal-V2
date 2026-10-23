<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_low_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_high_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('activity_at');
            $table->timestamps();
            $table->unique(['user_low_id', 'user_high_id']);
            $table->index(['activity_at', 'id']);
            $table->index(['user_low_id', 'activity_at', 'id']);
            $table->index(['user_high_id', 'activity_at', 'id']);
        });
        DB::statement('ALTER TABLE conversations ADD CONSTRAINT conversations_ordered_pair CHECK (user_low_id < user_high_id)');
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->unsignedBigInteger('hidden_through_message_id')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->primary(['conversation_id', 'user_id']);
            $table->index(['user_id', 'hidden_at', 'archived_at', 'conversation_id'], 'participants_inbox_index');
        });
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->foreignId('blocker_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['blocker_id', 'blocked_id']);
            $table->index(['blocked_id', 'blocker_id']);
        });
        DB::statement('ALTER TABLE user_blocks ADD CONSTRAINT user_blocks_not_self CHECK (blocker_id <> blocked_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
