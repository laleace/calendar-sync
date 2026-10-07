<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('base_url', 500)->nullable();
            // Encrypted JSON blob (username/password or OAuth tokens), see Account::casts().
            $table->text('credentials')->nullable();
            $table->string('principal_url', 500)->nullable();
            $table->string('calendar_home_url', 500)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->string('status_message', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
