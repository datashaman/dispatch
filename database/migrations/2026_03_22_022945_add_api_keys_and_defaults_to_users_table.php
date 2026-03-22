<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-provider API keys and default provider/model to users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('api_keys')->nullable()->after('remember_token');
            $table->string('default_provider')->nullable()->after('api_keys');
            $table->string('default_model')->nullable()->after('default_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_keys', 'default_provider', 'default_model']);
        });
    }
};
