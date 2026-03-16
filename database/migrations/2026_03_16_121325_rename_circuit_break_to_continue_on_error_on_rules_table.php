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
        Schema::table('rules', function (Blueprint $table) {
            $table->renameColumn('circuit_break', 'continue_on_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->renameColumn('continue_on_error', 'circuit_break');
        });
    }
};
