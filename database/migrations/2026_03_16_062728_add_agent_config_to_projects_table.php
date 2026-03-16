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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('agent_name')->nullable()->after('path');
            $table->string('agent_executor')->nullable()->after('agent_name');
            $table->string('agent_provider')->nullable()->after('agent_executor');
            $table->string('agent_model')->nullable()->after('agent_provider');
            $table->string('agent_instructions_file')->nullable()->after('agent_model');
            $table->json('agent_secrets')->nullable()->after('agent_instructions_file');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'agent_name',
                'agent_executor',
                'agent_provider',
                'agent_model',
                'agent_instructions_file',
                'agent_secrets',
            ]);
        });
    }
};
