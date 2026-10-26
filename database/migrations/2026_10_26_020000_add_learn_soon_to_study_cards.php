<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cards', 'study_card_drafts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('learn_soon')->default(false));
        }
    }

    public function down(): void
    {
        foreach (['cards', 'study_card_drafts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('learn_soon'));
        }
    }
};
