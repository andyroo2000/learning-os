<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->text('audio_storage_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->dropColumn('audio_storage_path');
        });
    }
};
