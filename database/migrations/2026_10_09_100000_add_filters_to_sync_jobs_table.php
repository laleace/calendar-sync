<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            // Include/exclude rules on the source events, see App\CalSync\Sync\EventFilters.
            $table->json('filters')->nullable()->after('skip_existing_in_target');
        });
    }

    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropColumn('filters');
        });
    }
};
