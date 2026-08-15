<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->string('origin', 24)
                // Keep the historical default migration-local so future enum
                // changes cannot alter a replayed schema.
                ->default('legacy')
                ->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
