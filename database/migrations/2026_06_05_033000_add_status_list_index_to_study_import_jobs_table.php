<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_LIST_INDEX = 'study_import_jobs_user_status_updated_id_idx';

    public function up(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'status', 'updated_at', 'id'],
                self::STATUS_LIST_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->dropIndex(self::STATUS_LIST_INDEX);
        });
    }
};
