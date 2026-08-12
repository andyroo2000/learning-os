<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const RETENTION_INDEX = 'content_gen_requests_retention_idx';

    public function up(): void
    {
        Schema::table('content_generation_requests', function (Blueprint $table): void {
            $table->index(['state', 'finished_at'], self::RETENTION_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('content_generation_requests', function (Blueprint $table): void {
            $table->dropIndex(self::RETENTION_INDEX);
        });
    }
};
