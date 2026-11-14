<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Abort rather than rewriting legacy data. Reconciliation requires review.
        if (DB::table('users')->whereNull('status')->orWhereRaw("CAST(status AS BINARY) NOT IN ('active','suspended','disabled','pending_deletion')")->exists()) {
            throw new LogicException('Existen estados de cuenta incompatibles; se requiere revisión sin modificación automática.');
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (CAST(status AS BINARY) IN ('active','suspended','disabled','pending_deletion'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_status_check');
    }
};
