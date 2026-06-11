<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->timestamp('upload_completed_at')->nullable();
        });

        DB::table('study_import_jobs')
            ->whereNotNull('uploaded_at')
            ->whereNull('upload_completed_at')
            ->update([
                'upload_completed_at' => DB::raw('uploaded_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->dropColumn('upload_completed_at');
        });
    }
};
