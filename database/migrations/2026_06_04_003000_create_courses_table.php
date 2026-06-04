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
        Schema::create('courses', function (Blueprint $table) {
            // Future child tables should reference this with foreignUlid('course_id'), not foreignId().
            $table->ulid('id')->primary();
            // users.id is the default bigint id column; switch this with a coordinated user-id migration.
            // User hard-delete is account erasure; owned courses are hard-deleted with the user.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // Keep status portable and easy to evolve; validate/cast with CourseStatus at application edges.
            $table->string('status', 32)->default('draft');
            $table->string('native_language', 16);
            $table->string('target_language', 16);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at', 'updated_at', 'id'], 'courses_user_deleted_updated_id_idx');
            $table->index(
                ['user_id', 'status', 'deleted_at', 'updated_at', 'id'],
                'courses_user_status_deleted_updated_id_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
