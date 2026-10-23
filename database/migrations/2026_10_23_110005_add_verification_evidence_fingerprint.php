<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_requests', function (Blueprint $table) {
            // Legacy submissions stay unsealed and must be reviewed/resubmitted, never auto-signed.
            $table->char('evidence_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Retirar el sello de las evidencias requiere un plan explícito.');
    }
};
