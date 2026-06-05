<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const IMPORT_JOB_INDEX = 'media_assets_import_job_id_idx';

    public const IMPORT_SOURCE_MEDIA_UNIQUE = 'media_import_source_ref_unique';

    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            // Keep this as indexed provenance for now; adding the FK in this alter
            // migration rewrites SQLite tables, so constrain it in a dedicated slice if needed.
            $table->ulid('import_job_id')
                ->nullable()
                ->after('user_id');
            $table->string('source_kind', 64)
                ->nullable()
                ->after('import_job_id');
            $table->string('source_media_ref')
                ->nullable()
                ->after('source_kind');
            $table->string('source_filename')
                ->nullable()
                ->after('source_media_ref');

            $table->index('import_job_id', self::IMPORT_JOB_INDEX);
            $table->unique(['import_job_id', 'source_media_ref'], self::IMPORT_SOURCE_MEDIA_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropUnique(self::IMPORT_SOURCE_MEDIA_UNIQUE);
            $table->dropIndex(self::IMPORT_JOB_INDEX);
            $table->dropColumn([
                'import_job_id',
                'source_kind',
                'source_media_ref',
                'source_filename',
            ]);
        });
    }
};
