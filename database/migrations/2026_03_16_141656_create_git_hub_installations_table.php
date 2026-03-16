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
        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->string('account_login');
            $table->string('account_type')->default('Organization');
            $table->unsignedBigInteger('account_id');
            $table->json('permissions')->nullable();
            $table->json('events')->nullable();
            $table->string('target_type')->default('Organization');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('github_installation_id')
                ->nullable()
                ->constrained('github_installations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_installation_id');
        });

        Schema::dropIfExists('github_installations');
    }
};
