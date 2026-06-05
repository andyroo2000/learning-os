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
        Schema::table('cards', function (Blueprint $table): void {
            $table->json('prompt_json')
                ->nullable()
                ->after('card_type');
            $table->json('answer_json')
                ->nullable()
                ->after('prompt_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn(['prompt_json', 'answer_json']);
        });
    }
};
