<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table): void {
            $table->string('sync_status', 16)->default('idle');
            $table->char('sync_run_id', 26)->nullable();
            $table->string('sync_error_code', 32)->nullable();
            $table->timestampTz('sync_status_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table): void {
            $table->dropColumn(['sync_status', 'sync_run_id', 'sync_error_code', 'sync_status_at']);
        });
    }
};
