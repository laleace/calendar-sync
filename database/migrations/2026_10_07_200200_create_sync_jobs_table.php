<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('enabled')->default(false);
            $table->foreignId('target_calendar_id')->constrained('calendars')->restrictOnDelete();
            $table->string('direction', 20)->default('one_way');
            $table->unsignedSmallInteger('range_past_days')->default(7);
            $table->unsignedSmallInteger('range_future_days')->default(60);
            $table->unsignedSmallInteger('interval_minutes')->default(15);
            $table->string('privacy_mode', 20)->default('full');
            $table->string('busy_title', 100)->default('Occupato');
            $table->string('title_prefix', 50)->nullable();
            // false = the same UID found in several sources produces a single copy.
            $table->boolean('allow_duplicates')->default(false);
            // true = skip source events whose UID already exists in the target (e.g. the original invite).
            $table->boolean('skip_existing_in_target')->default(false);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
        });

        Schema::create('sync_job_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained()->restrictOnDelete();
            // Lower value wins when deduplicating the same UID across sources.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['sync_job_id', 'calendar_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_job_sources');
        Schema::dropIfExists('sync_jobs');
    }
};
