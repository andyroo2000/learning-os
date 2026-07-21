<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_course_tombstones', function (Blueprint $table): void {
            $table->uuid('course_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->timestampTz('deleted_at');

            $table->index(
                ['user_id', 'convolab_user_id'],
                'content_course_tombstones_user_source_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_course_tombstones');
    }
};
