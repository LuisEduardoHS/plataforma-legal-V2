<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', fn (Blueprint $table) => $table->timestamp('editorial_published_at')->nullable());
    }

    public function down(): void
    {
        throw new LogicException('Retirar el historial editorial de adjuntos requiere un plan explícito.');
    }
};
