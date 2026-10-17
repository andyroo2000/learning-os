<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RETIRED_JOBS = [
        'App\\Jobs\\ProcessContentAudioGeneration',
        'App\\Jobs\\ProcessContentAudioScriptGeneration',
        'App\\Jobs\\ProcessContentCourseGeneration',
        'App\\Jobs\\ProcessContentDialogueGeneration',
        'App\\Jobs\\ProcessContentImageGeneration',
    ];

    public function up(): void
    {
        // Stop the retiring scheduler from recovering these requests during rollout.
        DB::table('content_generation_requests')->delete();
        foreach (['jobs', 'failed_jobs'] as $table) {
            DB::table($table)->select(['id', 'payload'])->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $payload = json_decode($row->payload, true);
                    if (in_array($payload['displayName'] ?? null, self::RETIRED_JOBS, true)) {
                        DB::table($table)->where('id', $row->id)->where('payload', $row->payload)->delete();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Cancelled generation requests must not be replayed on rollback.
    }
};
