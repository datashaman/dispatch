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
        Schema::table('rule_agent_configs', function (Blueprint $table) {
            $table->unsignedInteger('max_steps')->nullable()->after('max_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rule_agent_configs', function (Blueprint $table) {
            $table->dropColumn('max_steps');
        });
    }
};
