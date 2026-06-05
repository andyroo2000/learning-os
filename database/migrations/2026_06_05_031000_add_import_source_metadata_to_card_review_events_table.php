<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const IMPORT_JOB_INDEX = 'card_review_events_import_job_id_idx';

    public const IMPORT_SOURCE_REVIEW_UNIQUE = 'cre_import_source_review_unique';

    public function up(): void
    {
        Schema::table('card_review_events', function (Blueprint $table): void {
            // Keep this as indexed provenance for now; adding the FK in this alter
            // migration rewrites SQLite tables, so constrain it in a dedicated slice if needed.
            $table->ulid('import_job_id')
                ->nullable()
                ->after('card_id');
            $table->string('source_kind', 64)
                ->nullable()
                ->after('import_job_id');
            $table->unsignedBigInteger('source_review_id')
                ->nullable()
                ->after('source_kind');
            $table->unsignedBigInteger('source_card_id')
                ->nullable()
                ->after('source_review_id');
            $table->integer('source_ease')
                ->nullable()
                ->after('source_card_id');
            $table->integer('source_interval')
                ->nullable()
                ->after('source_ease');
            $table->integer('source_last_interval')
                ->nullable()
                ->after('source_interval');
            $table->integer('source_factor')
                ->nullable()
                ->after('source_last_interval');
            $table->unsignedInteger('source_time_ms')
                ->nullable()
                ->after('source_factor');
            $table->integer('source_review_type')
                ->nullable()
                ->after('source_time_ms');
            $table->json('raw_payload_json')
                ->nullable()
                ->after('source_review_type');

            $table->index('import_job_id', self::IMPORT_JOB_INDEX);
            $table->unique(['import_job_id', 'source_review_id'], self::IMPORT_SOURCE_REVIEW_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('card_review_events', function (Blueprint $table): void {
            $table->dropUnique(self::IMPORT_SOURCE_REVIEW_UNIQUE);
            $table->dropIndex(self::IMPORT_JOB_INDEX);
            $table->dropColumn([
                'import_job_id',
                'source_kind',
                'source_review_id',
                'source_card_id',
                'source_ease',
                'source_interval',
                'source_last_interval',
                'source_factor',
                'source_time_ms',
                'source_review_type',
                'raw_payload_json',
            ]);
        });
    }
};
