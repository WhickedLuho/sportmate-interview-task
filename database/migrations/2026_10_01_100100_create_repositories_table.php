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
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_target_id')->constrained()->cascadeOnDelete();
            // GitHub's numeric repository id; stable even when the repository is renamed.
            $table->unsignedBigInteger('external_id');
            $table->string('name');
            $table->string('full_name');
            $table->text('description')->nullable();
            $table->string('html_url');
            $table->string('language')->nullable();
            $table->unsignedInteger('stargazers_count')->default(0);
            $table->unsignedInteger('open_issues_count')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('external_updated_at')->nullable();
            // Set when GitHub stopped returning the repository (soft reconciliation).
            $table->timestamp('missing_at')->nullable();
            $table->timestamps();

            // Idempotent upserts: the same GitHub repository is stored once per target.
            $table->unique(['sync_target_id', 'external_id']);
            // Indexes backing the list's sort and filter options, scoped by target.
            $table->index(['sync_target_id', 'stargazers_count']);
            $table->index(['sync_target_id', 'external_updated_at']);
            $table->index(['sync_target_id', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
