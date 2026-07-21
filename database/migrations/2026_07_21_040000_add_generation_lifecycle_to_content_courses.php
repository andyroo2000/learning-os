<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation_attempt')->default(0);
            $table->string('generation_stage', 32)->nullable();
            $table->unsignedInteger('generation_progress')->nullable();
            $table->timestampTz('generation_heartbeat_at')->nullable();
            $table->text('generation_error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_courses', function (Blueprint $table): void {
            $table->dropColumn([
                'generation_attempt',
                'generation_stage',
                'generation_progress',
                'generation_heartbeat_at',
                'generation_error_message',
            ]);
        });
    }
};
