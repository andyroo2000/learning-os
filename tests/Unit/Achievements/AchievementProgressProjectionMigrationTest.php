<?php

namespace Tests\Unit\Achievements;

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

class AchievementProgressProjectionMigrationTest extends TestCase
{
    private const INDEX_NAMES = [
        'achievement_card_projection_user_card_idx',
        'achievement_study_projection_user_day_category_idx',
        'achievement_study_projection_user_episode_day_idx',
        'card_review_events_created_at_id_idx',
        'cards_deck_updated_id_idx',
        'study_sessions_user_updated_id_idx',
    ];

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     */
    #[DataProvider('portableGrammarProvider')]
    public function test_projection_schema_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $identifierQuote,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));
        $sql = implode("\n", [
            ...$this->progressBlueprint($connection)->toSql(),
            ...$this->cardBlueprint($connection)->toSql(),
            ...$this->studyBlueprint($connection)->toSql(),
            ...$this->sourceIndexBlueprints($connection),
        ]);

        foreach ([
            'achievement_progress_projections',
            'achievement_card_projections',
            'achievement_study_session_projections',
            ...self::INDEX_NAMES,
        ] as $identifier) {
            $this->assertStringContainsString($identifierQuote.$identifier.$identifierQuote, $sql);
        }
        $this->assertStringContainsString('metric_values', $sql);
        $this->assertStringContainsString('threshold_reached_at', $sql);
        $this->assertStringContainsString('needs_rebuild', $sql);
    }

    public function test_explicit_constraint_and_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([...self::INDEX_NAMES, 'achievement_study_session_id_fk'] as $identifier) {
            $this->assertLessThanOrEqual(63, strlen($identifier));
        }
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string}> */
    public static function portableGrammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`'],
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function progressBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'achievement_progress_projections', function (Blueprint $table): void {
            $table->create();
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('projection_version');
            $table->json('metric_values');
            $table->json('threshold_reached_at');
            $table->unsignedInteger('current_correct_run')->default(0);
            $table->unsignedBigInteger('conversation_ms')->default(0);
            $table->unsignedBigInteger('listening_ms')->default(0);
            $table->timestamp('last_review_created_at', 3)->nullable();
            $table->ulid('last_review_id')->nullable();
            $table->timestamp('latest_reviewed_at', 3)->nullable();
            $table->ulid('latest_reviewed_id')->nullable();
            $table->timestamp('latest_study_ended_at', 3)->nullable();
            $table->boolean('needs_rebuild')->default(false);
            $table->timestamps();
        });
    }

    private function cardBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'achievement_card_projections', function (Blueprint $table): void {
            $table->create();
            $table->foreignUlid('card_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->double('maximum_stability')->default(0);
            $table->timestamp('last_reviewed_at', 3)->nullable();
            $table->timestamp('source_updated_at', 3)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'card_id'], self::INDEX_NAMES[0]);
        });
    }

    private function studyBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'achievement_study_session_projections', function (Blueprint $table): void {
            $table->create();
            $table->ulid('study_activity_session_id')->primary();
            $table->foreign('study_activity_session_id', 'achievement_study_session_id_fk')
                ->references('id')->on('study_activity_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('study_day');
            $table->timestamp('ended_at', 3);
            $table->string('category', 24);
            $table->unsignedInteger('conversation_ms')->default(0);
            $table->unsignedInteger('listening_ms')->default(0);
            $table->string('daily_audio_episode', 120)->nullable();
            $table->timestamp('source_updated_at', 3);
            $table->timestamps();
            $table->index(['user_id', 'study_day', 'category'], self::INDEX_NAMES[1]);
            $table->index(['user_id', 'daily_audio_episode', 'study_day'], self::INDEX_NAMES[2]);
        });
    }

    /** @return list<string> */
    private function sourceIndexBlueprints(Connection $connection): array
    {
        return [
            ...((new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
                $table->index(['created_at', 'id'], self::INDEX_NAMES[3]);
            }))->toSql()),
            ...((new Blueprint($connection, 'cards', function (Blueprint $table): void {
                $table->index(['deck_id', 'updated_at', 'id'], self::INDEX_NAMES[4]);
            }))->toSql()),
            ...((new Blueprint($connection, 'study_activity_sessions', function (Blueprint $table): void {
                $table->index(['user_id', 'updated_at', 'id'], self::INDEX_NAMES[5]);
            }))->toSql()),
        ];
    }
}
