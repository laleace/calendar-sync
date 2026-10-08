<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            // Run that removes every copy created by the job instead of syncing.
            $table->boolean('purge')->default(false)->after('dry_run');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('purge');
        });
    }
};
