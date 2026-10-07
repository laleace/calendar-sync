<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_calendar_id')->constrained('calendars')->restrictOnDelete();
            $table->text('source_uid');
            // Normalized: 20261007T090000Z, or 20261007 for all-day instances.
            $table->string('recurrence_id', 32)->nullable();
            // sha256 of uid + recurrence_id (+ source calendar when duplicates are allowed).
            // UIDs can be very long, so uniqueness goes through this hash.
            $table->char('source_key', 64);
            $table->foreignId('target_calendar_id')->constrained('calendars')->restrictOnDelete();
            $table->string('target_event_id', 500);
            $table->string('target_etag')->nullable();
            $table->char('content_hash', 64);
            // Instance start, used to avoid deleting copies of events that simply left the sync window.
            $table->dateTime('starts_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sync_job_id', 'source_key']);
            $table->index(['sync_job_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_mappings');
    }
};
