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
        Schema::table('sync_targets', function (Blueprint $table) {
            // When a rate-limited synchronization will run again; null in every other state.
            $table->timestamp('retry_at')->nullable()->after('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_targets', function (Blueprint $table) {
            $table->dropColumn('retry_at');
        });
    }
};
