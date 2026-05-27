<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('card_media', function (Blueprint $table) {
            $table->foreignUlid('card_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['card_id', 'media_asset_id']);
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
