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
        Schema::create('rule_agent_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->integer('max_tokens')->nullable();
            $table->json('tools')->nullable();
            $table->json('disallowed_tools')->nullable();
            $table->boolean('isolation')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_agent_configs');
    }
};
