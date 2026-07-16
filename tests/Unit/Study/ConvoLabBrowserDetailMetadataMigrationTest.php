<?php

namespace Tests\Unit\Study;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConvoLabBrowserDetailMetadataMigrationTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'convolab_note_source_kind',
        'convolab_note_source_guid',
        'convolab_note_source_notetype_id',
        'convolab_note_raw_fields_json',
        'convolab_note_canonical_json',
        'source_deck_name',
        'source_template_name',
        'source_queue',
        'source_card_type',
        'source_due',
        'source_interval',
        'source_factor',
        'source_reps',
        'source_lapses',
        'source_left',
        'source_original_due',
        'source_original_deck_id',
        'source_fsrs_json',
        'answer_audio_source',
    ];

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_16_040000_add_convolab_browser_detail_metadata_to_cards_table.php',
        );
    }

    #[DataProvider('connectionProvider')]
    public function test_browser_detail_metadata_and_rollback_compile_for_each_database(
        string $connectionClass,
        string $grammarClass,
        string $identifierQuote,
        array $expectedTypeFragments,
        int $expectedDownStatementCount,
    ): void {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $upSql = $this->upBlueprint($connection)->toSql();
        $downSql = $this->downBlueprint($connection)->toSql();
        $compiledUp = implode("\n", $upSql);
        $compiledDown = implode("\n", $downSql);

        foreach (self::COLUMNS as $column) {
            $quotedColumn = $identifierQuote.$column.$identifierQuote;
            $this->assertStringContainsString($quotedColumn, $compiledUp);
            $this->assertStringContainsString($quotedColumn, $compiledDown);
        }

        foreach ($expectedTypeFragments as $fragment) {
            $this->assertStringContainsString($fragment, $compiledUp);
        }

        $this->assertCount(count(self::COLUMNS), $upSql);
        $this->assertCount($expectedDownStatementCount, $downSql);
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, list<string>, int}> */
    public static function connectionProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"', [
                '"convolab_note_source_kind" varchar',
                '"convolab_note_source_notetype_id" integer',
                '"convolab_note_raw_fields_json" text',
                '"source_queue" integer',
                '"source_original_deck_id" integer',
                '"source_fsrs_json" text',
                '"answer_audio_source" varchar',
            ], count(self::COLUMNS)],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"', [
                '"convolab_note_source_kind" varchar(64)',
                '"convolab_note_source_notetype_id" bigint',
                '"convolab_note_raw_fields_json" json',
                '"source_queue" integer',
                '"source_original_deck_id" bigint',
                '"source_fsrs_json" json',
                '"answer_audio_source" varchar(32)',
            ], 1],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`', [
                '`convolab_note_source_kind` varchar(64)',
                '`convolab_note_source_notetype_id` bigint unsigned',
                '`convolab_note_raw_fields_json` json',
                '`source_queue` int',
                '`source_original_deck_id` bigint unsigned',
                '`source_fsrs_json` json',
                '`answer_audio_source` varchar(32)',
            ], 1],
        ];
    }

    private function upBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->string('convolab_note_source_kind', 64)->nullable();
            $table->string('convolab_note_source_guid')->nullable();
            $table->unsignedBigInteger('convolab_note_source_notetype_id')->nullable();
            $table->json('convolab_note_raw_fields_json')->nullable();
            $table->json('convolab_note_canonical_json')->nullable();
            $table->string('source_deck_name')->nullable();
            $table->string('source_template_name')->nullable();
            $table->integer('source_queue')->nullable();
            $table->integer('source_card_type')->nullable();
            $table->integer('source_due')->nullable();
            $table->integer('source_interval')->nullable();
            $table->integer('source_factor')->nullable();
            $table->integer('source_reps')->nullable();
            $table->integer('source_lapses')->nullable();
            $table->integer('source_left')->nullable();
            $table->integer('source_original_due')->nullable();
            $table->unsignedBigInteger('source_original_deck_id')->nullable();
            $table->json('source_fsrs_json')->nullable();
            $table->string('answer_audio_source', 32)->nullable();
        });
    }

    private function downBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
}
