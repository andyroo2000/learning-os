<?php

namespace Tests\Unit\Journal;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalQuestionAudioMigrationTest extends TestCase
{
    #[DataProvider('grammars')]
    public function test_actual_migration_and_rollback_compile_for_each_database(string $connectionClass, string $grammarClass): void
    {
        // SQLite PDO is only a compile-time stand-in; no dialect-specific SQL is executed.
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'test');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/2026_11_13_001000_add_journal_question_audio.php');
        $create = $connection->pretend(fn () => $migration->up());
        $drop = $connection->pretend(fn () => $migration->down());
        $sql = strtolower(str_replace(['"', '`'], '', implode("\n", array_column($create, 'query'))));

        foreach (['audio_status', 'audio_attempt_id', 'audio_requested_at', 'audio_path', 'audio_error'] as $column) {
            $this->assertStringContainsString($column, $sql);
            $this->assertStringContainsString($column, implode("\n", array_column($drop, 'query')));
        }

    }

    public static function grammars(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
        ];
    }
}
