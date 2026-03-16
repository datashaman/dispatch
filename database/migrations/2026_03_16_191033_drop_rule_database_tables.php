<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop in dependency order (foreign keys first)
        Schema::dropIfExists('filters');
        Schema::dropIfExists('rule_agent_configs');
        Schema::dropIfExists('rule_output_configs');
        Schema::dropIfExists('rule_retry_configs');
        Schema::dropIfExists('rules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // These tables are no longer needed — rules live in dispatch.yml
    }
};
