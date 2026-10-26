<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_piles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('source_reference', 512)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'position', 'id']);
        });

        Schema::create('study_pile_chapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('pile_id')->constrained('study_piles')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('source_reference', 512)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['pile_id', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_pile_chapters');
        Schema::dropIfExists('study_piles');
    }
};
