<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clear existing agent_secrets values (env var name mappings)
     * so they can be replaced with encrypted API key values.
     */
    public function up(): void
    {
        DB::table('projects')->update(['agent_secrets' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot restore original env var name mappings
    }
};
