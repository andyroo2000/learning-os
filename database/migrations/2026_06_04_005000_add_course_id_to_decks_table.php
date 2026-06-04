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
        Schema::table('decks', function (Blueprint $table): void {
            $table->foreignUlid('course_id')
                ->nullable()
                ->after('user_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->index(
                ['user_id', 'course_id', 'deleted_at', 'created_at', 'id'],
                'decks_user_course_deleted_created_id_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->dropIndex('decks_user_course_deleted_created_id_idx');
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
