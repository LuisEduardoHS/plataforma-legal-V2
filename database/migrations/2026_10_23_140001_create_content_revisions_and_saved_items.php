<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('legal_resource_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['article_id', 'version']);
            $table->unique(['legal_resource_id', 'version']);
        });
        DB::statement('ALTER TABLE content_revisions ADD CONSTRAINT revisions_one_target CHECK ((article_id IS NOT NULL) + (legal_resource_id IS NOT NULL) = 1)');
        Schema::create('content_revision_attachments', function (Blueprint $table) {
            $table->foreignId('content_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['content_revision_id', 'attachment_id']);
            $table->unique(['content_revision_id', 'position'], 'revision_attachment_position_unique');
            $table->index(['attachment_id', 'content_revision_id'], 'revision_attachment_reverse');
        });
        Schema::create('saved_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('legal_resource_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'post_id']);
            $table->unique(['user_id', 'article_id']);
            $table->unique(['user_id', 'legal_resource_id']);
            $table->index(['user_id', 'created_at', 'id']);
        });
        DB::statement('ALTER TABLE saved_items ADD CONSTRAINT saved_items_one_target CHECK ((post_id IS NOT NULL) + (article_id IS NOT NULL) + (legal_resource_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_items');
        Schema::dropIfExists('content_revision_attachments');
        Schema::dropIfExists('content_revisions');
    }
};
