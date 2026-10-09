<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Not "groups": GROUPS is a reserved word in MySQL 8.
        Schema::create('sync_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        foreach (['accounts', 'sync_jobs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Organizational only (e.g. "Personale", "Utente X"): no effect on syncing.
                $table->foreignId('group_id')->nullable()->after('name')->constrained('sync_groups')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['accounts', 'sync_jobs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('group_id');
            });
        }

        Schema::dropIfExists('sync_groups');
    }
};
