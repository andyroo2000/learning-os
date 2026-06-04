<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const CARD_MEDIA_PAIR_UNIQUE_INDEX = 'card_media_card_id_media_asset_id_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('card_media', function (Blueprint $table) {
            $table->foreignUlid('card_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['card_id', 'media_asset_id'], self::CARD_MEDIA_PAIR_UNIQUE_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_media');
    }
};
