<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')->constrained()->cascadeOnDelete();
            $table->string('triggered_by', 20);
            $table->boolean('dry_run')->default(false);
            $table->string('status', 20)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            // Sanitized error entries: [{op, source, uid, message}], capped.
            $table->json('errors')->nullable();
            // Dry-run only: planned operations with an event preview, capped.
            $table->json('plan')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->index(['sync_job_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
