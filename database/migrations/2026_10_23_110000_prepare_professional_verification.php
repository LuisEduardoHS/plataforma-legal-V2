<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE professional_profiles MODIFY verification_status ENUM('unverified','pending','verified','rejected','revoked') NOT NULL DEFAULT 'unverified'");
        Schema::table('attachments', function (Blueprint $table) {
            $table->timestamp('verification_retained_at')->nullable();
        });
    }

    public function down(): void
    {
        // Downgrades require an explicit data plan; never silently remap revoked decisions.
        throw new LogicException('La reversión de la retención y estados de verificación requiere un plan explícito.');
    }
};
