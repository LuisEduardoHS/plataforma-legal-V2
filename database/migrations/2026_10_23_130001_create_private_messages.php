<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sender_id');
            $table->text('content')->nullable();
            $table->enum('status', ['sent', 'deleted'])->default('sent');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('client_token')->nullable();
            $table->char('request_hash', 64);
            $table->timestamp('sent_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'sender_id', 'client_token'], 'messages_client_token_unique');
            $table->foreign(['conversation_id', 'sender_id'])->references(['conversation_id', 'user_id'])->on('conversation_participants')->restrictOnDelete();
            $table->index(['conversation_id', 'status', 'id']);
            $table->index(['sender_id', 'id']);
        });
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_lifecycle_check CHECK (
            (status = 'sent' AND deleted_at IS NULL) OR (status = 'deleted' AND deleted_at IS NOT NULL AND content IS NULL)
        )");
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->foreign(['conversation_id', 'last_read_message_id'], 'participants_read_message_fk')->references(['conversation_id', 'id'])->on('messages')->restrictOnDelete();
            $table->foreign(['conversation_id', 'hidden_through_message_id'], 'participants_hidden_message_fk')->references(['conversation_id', 'id'])->on('messages')->restrictOnDelete();
        });
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->primary(['message_id', 'attachment_id']);
            $table->unique(['message_id', 'position']);
            $table->index(['attachment_id', 'message_id']);
        });
        Schema::create('message_user_hides', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at');
            $table->primary(['message_id', 'user_id']);
            $table->foreign(['conversation_id', 'message_id'])->references(['conversation_id', 'id'])->on('messages')->restrictOnDelete();
            $table->foreign(['conversation_id', 'user_id'])->references(['conversation_id', 'user_id'])->on('conversation_participants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_user_hides');
        Schema::dropIfExists('message_attachments');
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropForeign('participants_read_message_fk');
            $table->dropForeign('participants_hidden_message_fk');
        });
        Schema::dropIfExists('messages');
    }
};
