<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'google_calendar_provider_account_unique';

    public function up(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table): void {
            $table->string('provider_account_id', 255)->change();
            $table->unique('provider_account_id', self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
            $table->string('provider_account_id', 1024)->change();
        });
    }
};
