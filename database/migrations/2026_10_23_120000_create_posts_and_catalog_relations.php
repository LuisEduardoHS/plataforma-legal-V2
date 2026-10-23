<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('content')->nullable();
            $table->foreignId('content_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('legal_specialty_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'published', 'hidden', 'deleted'])->default('draft');
            $table->enum('visibility', ['public', 'private'])->default('private');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'visibility', 'published_at', 'id'], 'posts_public_feed_index');
            $table->index(['user_id', 'status', 'published_at', 'id'], 'posts_author_feed_index');
            $table->index(['content_category_id', 'status', 'published_at', 'id'], 'posts_category_feed_index');
            $table->index(['legal_specialty_id', 'status', 'published_at', 'id'], 'posts_specialty_feed_index');
        });
        DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_lifecycle_check CHECK (
            (status = 'deleted' AND deleted_at IS NOT NULL)
            OR (status = 'draft' AND deleted_at IS NULL AND published_at IS NULL)
            OR (status IN ('published','hidden') AND deleted_at IS NULL AND published_at IS NOT NULL)
        )");
        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['post_id', 'tag_id']);
            $table->index(['tag_id', 'post_id']);
        });
        Schema::create('post_attachments', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->primary(['post_id', 'attachment_id']);
            $table->unique(['post_id', 'position']);
            $table->index(['attachment_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_attachments');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('posts');
    }
};
