<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // CalDAV collection href or Google calendarId.
            $table->string('remote_id', 500);
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('can_write')->default(false);
            // Set when discovery no longer returns the calendar; rows are kept because jobs may reference them.
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendars');
    }
};
