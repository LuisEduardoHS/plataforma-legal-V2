<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['verification_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_events');
    }
};
