<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONVOLAB_TIMESTAMP_PRECISION = 3;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite stores these datetimes as text and already retains fractional seconds.
            return;
        }

        $this->changePrecision(self::CONVOLAB_TIMESTAMP_PRECISION);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->changePrecision(0);
    }

    private function changePrecision(int $precision): void
    {
        Schema::table('cards', function (Blueprint $table) use ($precision): void {
            $table->timestamp('due_at', $precision)->nullable()->change();
        });

        Schema::table('study_import_jobs', function (Blueprint $table) use ($precision): void {
            $table->timestamp('completed_at', $precision)->nullable()->change();
        });
    }
};
