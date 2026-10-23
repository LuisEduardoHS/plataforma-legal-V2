<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['articles', 'legal_resources'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('title', 200);
                $table->string('slug', 190)->unique();
                if ($name === 'articles') {
                    $table->text('summary')->nullable();
                    $table->longText('body')->nullable();
                } else {
                    $table->text('description')->nullable();
                    $table->enum('resource_type', ['guide', 'document', 'template', 'institutional_link', 'educational_material']);
                    $table->string('external_url', 2048)->nullable();
                }
                $table->foreignId('content_category_id')->nullable()->constrained()->restrictOnDelete();
                $table->enum('status', ['draft', 'published', 'hidden', 'archived', 'deleted'])->default('draft');
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('published_at')->nullable();
                $table->timestamp('editorial_updated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status', 'published_at', 'id'], $name.'_recent_index');
                $table->index(['user_id', 'status', 'published_at', 'id'], $name.'_author_index');
                $table->index(['content_category_id', 'status', 'published_at', 'id'], $name.'_category_index');
                $table->index('title');
                if ($name === 'legal_resources') {
                    $table->index(['resource_type', 'status', 'published_at', 'id'], 'resources_type_index');
                }
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_lifecycle CHECK (
                (status = 'deleted' AND deleted_at IS NOT NULL) OR
                (status <> 'deleted' AND deleted_at IS NULL AND (status <> 'published' OR published_at IS NOT NULL))
            )");
            $key = $name === 'articles' ? 'article_id' : 'legal_resource_id';
            $prefix = $name === 'articles' ? 'article' : 'legal_resource';
            foreach (['tag' => 'tags', 'specialty' => 'legal_specialties', 'attachment' => 'attachments'] as $relation => $target) {
                $relatedKey = $relation === 'specialty' ? 'legal_specialty_id' : $relation.'_id';
                Schema::create($prefix.'_'.$relation, function (Blueprint $table) use ($name, $key, $target, $relatedKey, $relation, $prefix) {
                    $table->foreignId($key)->constrained($name)->restrictOnDelete();
                    $table->foreignId($relatedKey)->constrained($target)->restrictOnDelete();
                    $table->timestamps();
                    $table->primary([$key, $relatedKey]);
                    $table->index([$relatedKey, $key], $prefix.'_'.$relation.'_reverse');
                    if ($relation === 'attachment') {
                        $table->unsignedSmallInteger('position');
                        $table->unique([$key, 'position'], $prefix.'_file_position_unique');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['legal_resource', 'article'] as $prefix) {
            foreach (['attachment', 'specialty', 'tag'] as $suffix) {
                Schema::dropIfExists($prefix.'_'.$suffix);
            }
        }
        Schema::dropIfExists('legal_resources');
        Schema::dropIfExists('articles');
    }
};
