<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLEANUP_INDEX = 'study_import_jobs_archive_cleanup_idx';

    public function up(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->timestamp('archive_cleanup_attempted_at')->nullable();
            $table->timestamp('archive_cleanup_resolved_at')->nullable();
            $table->text('archive_cleanup_error')->nullable();
            $table->index(
                ['status', 'archive_cleanup_resolved_at', 'completed_at', 'id'],
                self::CLEANUP_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->dropIndex(self::CLEANUP_INDEX);
            $table->dropColumn([
                'archive_cleanup_attempted_at',
                'archive_cleanup_resolved_at',
                'archive_cleanup_error',
            ]);
        });
    }
};
