<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const IMPORT_JOB_INDEX = 'cards_import_job_id_idx';

    public const IMPORT_SOURCE_CARD_UNIQUE = 'cards_import_source_card_unique';

    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            // Keep this as indexed provenance for now; adding the FK in this alter
            // migration rewrites SQLite tables, so constrain it in a dedicated slice if needed.
            $table->ulid('import_job_id')
                ->nullable()
                ->after('deck_id');
            $table->string('source_kind', 64)
                ->nullable()
                ->after('import_job_id');
            $table->unsignedBigInteger('source_card_id')
                ->nullable()
                ->after('source_kind');
            $table->unsignedBigInteger('source_note_id')
                ->nullable()
                ->after('source_card_id');
            $table->unsignedBigInteger('source_deck_id')
                ->nullable()
                ->after('source_note_id');
            $table->string('source_notetype_name')
                ->nullable()
                ->after('source_deck_id');
            $table->unsignedInteger('source_template_ord')
                ->nullable()
                ->after('source_notetype_name');

            $table->index('import_job_id', self::IMPORT_JOB_INDEX);
            $table->unique(['import_job_id', 'source_card_id'], self::IMPORT_SOURCE_CARD_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropUnique(self::IMPORT_SOURCE_CARD_UNIQUE);
            $table->dropIndex(self::IMPORT_JOB_INDEX);
            $table->dropColumn([
                'import_job_id',
                'source_kind',
                'source_card_id',
                'source_note_id',
                'source_deck_id',
                'source_notetype_name',
                'source_template_ord',
            ]);
        });
    }
};
