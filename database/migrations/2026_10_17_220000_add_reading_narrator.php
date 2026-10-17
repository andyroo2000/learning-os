<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', fn (Blueprint $table) => $table->string('narrator_voice_id', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('readings', fn (Blueprint $table) => $table->dropColumn('narrator_voice_id'));
    }
};
