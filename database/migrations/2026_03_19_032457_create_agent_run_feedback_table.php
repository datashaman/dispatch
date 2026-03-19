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
        Schema::create('agent_run_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rating'); // 'helpful' or 'not_helpful'
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['agent_run_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_run_feedback');
    }
};
