<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexa_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lexa_conversation_id')->constrained('lexa_conversations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_message_id')->constrained('lexa_messages')->restrictOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('lexa_messages')->restrictOnDelete();
            $table->char('attempt_token', 36);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['lexa_conversation_id', 'user_message_id'], 'lexa_gen_conv_user_msg_unique');
            $table->index(['lexa_conversation_id', 'status', 'id'], 'lexa_gen_conv_status_idx');
            $table->foreign(['user_id', 'lexa_conversation_id'], 'lexa_gen_owner_conv_fk')
                ->references(['user_id', 'id'])
                ->on('lexa_conversations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexa_generations');
    }
};
