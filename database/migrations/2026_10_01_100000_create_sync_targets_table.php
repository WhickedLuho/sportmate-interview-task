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
        Schema::create('sync_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // GitHub logins are case-insensitive; the model stores them lowercased.
            $table->string('name');
            // Discovered from GitHub on the first sync, so unknown until then.
            $table->string('type')->nullable();
            $table->string('status')->default('idle');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            // One target per GitHub account per user (also serves lookups by user_id).
            $table->unique(['user_id', 'name']);
            // Used by the scheduled sync to find targets that are due.
            $table->index(['status', 'last_synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_targets');
    }
};
