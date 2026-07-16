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
            $table->timestamp('created_at', $precision)->nullable()->change();
            $table->timestamp('updated_at', $precision)->nullable()->change();
        });

        Schema::table('card_review_events', function (Blueprint $table) use ($precision): void {
            $table->timestamp('reviewed_at', $precision)->change();
        });
    }
};
