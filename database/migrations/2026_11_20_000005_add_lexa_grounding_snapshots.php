<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lexa_generations', fn (Blueprint $table) => $table->json('retrieval_json')->nullable());
        Schema::table('lexa_messages', fn (Blueprint $table) => $table->json('grounding_json')->nullable());
    }

    public function down(): void
    {
        if (DB::table('lexa_generations')->whereNotNull('retrieval_json')->exists() || DB::table('lexa_messages')->whereNotNull('grounding_json')->exists()) {
            throw new RuntimeException('No se puede eliminar trazabilidad persistida.');
        }
        Schema::table('lexa_messages', fn (Blueprint $table) => $table->dropColumn('grounding_json'));
        Schema::table('lexa_generations', fn (Blueprint $table) => $table->dropColumn('retrieval_json'));
    }
};
