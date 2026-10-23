<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('content');
            $table->enum('status', ['visible', 'hidden', 'deleted'])->default('visible');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id', 'post_id']);
            $table->foreign(['parent_id', 'post_id'])->references(['id', 'post_id'])->on('comments')->restrictOnDelete();
            $table->index(['post_id', 'status', 'id']);
            $table->index(['user_id', 'id']);
        });
        DB::statement("ALTER TABLE comments ADD CONSTRAINT comments_lifecycle_check CHECK (
            (status = 'deleted' AND deleted_at IS NOT NULL)
            OR (status IN ('visible','hidden') AND deleted_at IS NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
