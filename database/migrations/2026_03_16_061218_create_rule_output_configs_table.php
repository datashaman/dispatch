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
        Schema::create('rule_output_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained()->cascadeOnDelete();
            $table->boolean('log')->default(true);
            $table->boolean('github_comment')->default(false);
            $table->string('github_reaction')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_output_configs');
    }
};
