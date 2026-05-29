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
        Schema::create('media_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('original_filename')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index('checksum_sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
