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
        Schema::table('card_review_events', function (Blueprint $table) {
            $table->json('card_state_before')
                ->nullable()
                ->after('client_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_review_events', function (Blueprint $table) {
            $table->dropColumn('card_state_before');
        });
    }
};
