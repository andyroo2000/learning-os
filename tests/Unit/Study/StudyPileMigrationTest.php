<?php

namespace Tests\Unit\Study;

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

class StudyPileMigrationTest extends TestCase
{
    #[DataProvider('grammars')]
    public function test_real_migration_compiles_and_rolls_back_for_supported_databases(string $connectionClass, string $grammarClass): void
    {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'test');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/2026_10_26_000000_create_study_piles.php');
        $create = $connection->pretend(fn () => $migration->up());
        $drop = $connection->pretend(fn () => $migration->down());
        $sql = implode("\n", array_column($create, 'query'));

        $this->assertStringContainsString('study_piles', $sql);
        $this->assertStringContainsString('study_pile_chapters', $sql);
        $this->assertStringContainsString('study_piles_user_id_position_id_index', $sql);
        $this->assertStringContainsString('study_pile_chapters_pile_id_position_id_index', $sql);
        $this->assertStringContainsString('study_pile_chapters', $drop[0]['query']);
        $this->assertStringContainsString('study_piles', $drop[1]['query']);
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
