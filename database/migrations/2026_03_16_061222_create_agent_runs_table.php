<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_log_id')->constrained()->cascadeOnDelete();
            $table->string('rule_id');
            $table->string('status')->default('queued');
            $table->text('output')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
