<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->index(
                ['is_test_course', 'created_at', 'id'],
                'content_courses_test_created_id_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->dropIndex('content_courses_test_created_id_idx');
        });
    }
};
