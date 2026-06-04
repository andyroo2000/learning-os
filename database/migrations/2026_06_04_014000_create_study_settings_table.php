<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_NEW_CARDS_PER_DAY = 20;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('study_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('new_cards_per_day')
                ->default(self::DEFAULT_NEW_CARDS_PER_DAY);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_settings');
    }
};
