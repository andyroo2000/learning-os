<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_COLUMNS = [
        'id',
        'courseId',
        'unitIndex',
        'text',
        'speed',
        'voiceId',
        'audioUrl',
        'createdAt',
    ];

    public function up(): void
    {
        Schema::create('admin_course_line_renderings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('content_courses')->cascadeOnDelete();
            $table->unsignedInteger('unit_index');
            $table->text('text');
            $table->double('speed')->default(1);
            $table->string('voice_id');
            $table->text('audio_url');
            $table->text('audio_storage_path')->nullable();
            $table->timestampTz('created_at', 3);

            $table->index(
                ['course_id', 'unit_index'],
                'admin_course_line_renderings_order_idx',
            );
        });

        $this->copyLegacyRenderings();
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_course_line_renderings');
    }

    private function copyLegacyRenderings(): void
    {
        if (! Schema::hasTable('line_audio_renderings')
            || ! Schema::hasColumns('line_audio_renderings', self::LEGACY_COLUMNS)) {
            return;
        }

        // Test renderings are low volume; bounded chunks keep this portable and avoid a long read buffer.
        DB::table('line_audio_renderings as legacy')
            ->join('content_courses as courses', 'courses.id', '=', 'legacy.courseId')
            ->select([
                'legacy.id',
                'legacy.courseId as course_id',
                'legacy.unitIndex as unit_index',
                'legacy.text',
                'legacy.speed',
                'legacy.voiceId as voice_id',
                'legacy.audioUrl as audio_url',
                'legacy.createdAt as created_at',
            ])
            ->orderBy('legacy.id')
            ->chunk(500, function ($rows): void {
                DB::table('admin_course_line_renderings')->insertOrIgnore(
                    $rows->map(fn (object $row): array => (array) $row)->all(),
                );
            });
    }
};
