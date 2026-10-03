<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_targets', function (Blueprint $table) {
            $table->uuid('sync_run_id')->nullable();
            $table->uuid('dispatch_id')->nullable();
            $table->unsignedInteger('next_page')->default(1);
            $table->string('sync_query_signature', 64)->nullable();
            $table->timestamp('last_page_saved_at')->nullable();
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->uuid('last_seen_sync_run_id')->nullable();
            $table->index(['sync_target_id', 'last_seen_sync_run_id']);
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropIndex(['sync_target_id', 'last_seen_sync_run_id']);
            $table->dropColumn('last_seen_sync_run_id');
        });

        Schema::table('sync_targets', function (Blueprint $table) {
            $table->dropColumn(['sync_run_id', 'dispatch_id', 'next_page', 'sync_query_signature', 'last_page_saved_at']);
        });
    }
};
