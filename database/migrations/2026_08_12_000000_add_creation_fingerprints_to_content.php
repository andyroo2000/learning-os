<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->string('creation_fingerprint', 64)->nullable();
        });
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->string('creation_fingerprint', 64)->nullable();
            $table->uuid('description_generation_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->dropColumn('creation_fingerprint');
            $table->dropColumn('description_generation_token');
        });
        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->dropColumn('creation_fingerprint');
        });
    }
};
