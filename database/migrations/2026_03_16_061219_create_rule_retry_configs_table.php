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
        Schema::create('rule_retry_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->integer('max_attempts')->default(3);
            $table->integer('delay')->default(60);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_retry_configs');
    }
};
