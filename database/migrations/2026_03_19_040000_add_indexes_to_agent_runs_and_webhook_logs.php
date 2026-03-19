<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->index('status');
            $table->index('created_at');
            $table->index(['webhook_log_id', 'status']);
        });

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->index('status');
            $table->index('repo');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['webhook_log_id', 'status']);
        });

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['repo']);
            $table->dropIndex(['created_at']);
        });
    }
};
