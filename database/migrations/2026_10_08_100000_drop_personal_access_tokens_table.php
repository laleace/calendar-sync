<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The n8n API (and Sanctum with it) was dropped from the project on 2026-10-08.
 * Removes the token table from databases migrated before that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }

    public function down(): void
    {
        //
    }
};
