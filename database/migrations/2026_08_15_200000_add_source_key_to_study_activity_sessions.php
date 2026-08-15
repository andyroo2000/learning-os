<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'study_activity_provider_source_unique';

    public function up(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            // Provider IDs can be 1024 characters. Persist only their fixed-size
            // canonical hash so the cross-database unique index remains bounded.
            $table->char('source_key', 64)->nullable()->after('origin');
            $table->unique(['user_id', 'origin', 'source_key'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('source_key');
        });
    }
};
