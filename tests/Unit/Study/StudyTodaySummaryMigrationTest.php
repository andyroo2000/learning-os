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

class StudyTodaySummaryMigrationTest extends TestCase
{
    #[DataProvider('grammarProvider')]
    public function test_wanikani_summary_columns_compile_up_and_down_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $up = new Blueprint($connection, 'wanikani_connections', function (Blueprint $table): void {
            $table->unsignedInteger('review_count')->nullable()->after('last_synced_at');
            $table->timestamp('review_count_updated_at', 6)->nullable()->after('review_count');
        });
        $down = new Blueprint($connection, 'wanikani_connections', function (Blueprint $table): void {
            $table->dropColumn(['review_count', 'review_count_updated_at']);
        });
        $calendarUp = new Blueprint($connection, 'google_calendar_event_mirrors', function (Blueprint $table): void {
            $table->index(
                ['google_calendar_connection_id', 'status', 'starts_at'],
                'google_calendar_event_mirrors_next_lesson_index',
            );
        });
        $calendarDown = new Blueprint($connection, 'google_calendar_event_mirrors', function (Blueprint $table): void {
            $table->dropIndex('google_calendar_event_mirrors_next_lesson_index');
        });

        $this->assertNotEmpty($up->toSql());
        $this->assertNotEmpty($down->toSql());
        $this->assertNotEmpty($calendarUp->toSql());
        $this->assertNotEmpty($calendarDown->toSql());
        $this->assertStringContainsString('review_count', implode(' ', $up->toSql()));
        $this->assertStringContainsString('review_count_updated_at', implode(' ', $down->toSql()));
        $this->assertStringContainsString(
            'google_calendar_event_mirrors_next_lesson_index',
            implode(' ', $calendarUp->toSql()),
        );
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
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
}
