<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexa_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 120);
            $table->enum('mode', ['orientation', 'contract', 'organize'])->default('orientation');
            $table->enum('status', ['active', 'archived', 'deleted'])->default('active');
            $table->text('summary')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'lexa_conv_user_id_unique');
            $table->index(['user_id', 'status', 'last_message_at', 'id'], 'lexa_conv_user_feed_idx');
        });

        DB::statement("ALTER TABLE lexa_conversations ADD CONSTRAINT lexa_conversations_lifecycle_check CHECK (
            (status IN ('active', 'archived') AND deleted_at IS NULL) OR (status = 'deleted' AND deleted_at IS NOT NULL)
        )");

        Schema::create('lexa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lexa_conversation_id')->constrained('lexa_conversations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->enum('status', ['persisted', 'completed', 'failed'])->default('persisted');
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('citations_json')->nullable();
            $table->timestamps();

            $table->index(['lexa_conversation_id', 'id'], 'lexa_msg_conv_order_idx');
            $table->index(['user_id', 'lexa_conversation_id', 'id'], 'lexa_msg_user_conv_idx');
            $table->foreign(['user_id', 'lexa_conversation_id'], 'lexa_msg_owner_conv_fk')
                ->references(['user_id', 'id'])
                ->on('lexa_conversations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexa_messages');
        Schema::dropIfExists('lexa_conversations');
    }
};
