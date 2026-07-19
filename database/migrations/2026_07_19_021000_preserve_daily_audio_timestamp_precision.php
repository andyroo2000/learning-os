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
        foreach (['daily_audio_practices', 'daily_audio_practice_tracks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($precision): void {
                $table->timestamp('created_at', $precision)->nullable()->change();
                $table->timestamp('updated_at', $precision)->nullable()->change();
            });
        }
    }
};
