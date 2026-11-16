<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 48);
            $table->string('resource_type', 24);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('permission_name', 100)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->text('reason');
            $table->enum('source', ['http', 'console', 'recovery']);
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedTinyInteger('bootstrap_slot')->nullable()
                ->storedAs("CASE WHEN action = 'operator_bootstrapped' THEN 1 ELSE NULL END");
            $table->unique('bootstrap_slot');
            $table->index(['target_user_id', 'id']);
            $table->index(['actor_id', 'id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('operations_events') && DB::table('operations_events')->exists()) {
            throw new LogicException('No se puede retirar auditoría persistida sin un plan explícito de conservación.');
        }
        Schema::dropIfExists('operations_events');
    }
};
