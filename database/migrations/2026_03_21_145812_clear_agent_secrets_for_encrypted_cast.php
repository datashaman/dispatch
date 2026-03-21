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
     * This migration is destructive and cannot be reversed.
     */
    public function down(): void
    {
        throw new RuntimeException('Migration is irreversible: original agent_secrets values cannot be restored.');
    }
};
