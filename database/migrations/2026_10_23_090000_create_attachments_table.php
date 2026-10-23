<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_name', 80)->unique();
            $table->string('path', 255);
            $table->string('disk', 50);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->enum('category', ['image', 'video', 'document', 'other']);
            $table->string('visibility', 20)->default('private');
            $table->enum('status', ['pending_upload', 'available', 'pending_deletion', 'deleted'])->default('pending_upload');
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
