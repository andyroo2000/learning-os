<?php

namespace Tests\Unit\FeatureFlags;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeatureAudienceMigrationTest extends TestCase
{
    #[DataProvider('grammars')]
    public function test_actual_schema_seed_and_rollback_are_portable(string $connectionClass, string $grammarClass): void
    {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'test');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        Schema::swap($connection->getSchemaBuilder());
        DB::swap($connection);
        $migration = require database_path('migrations/2026_11_27_200000_create_feature_audiences.php');
        $create = $connection->pretend(fn () => $migration->up());
        $drop = $connection->pretend(fn () => $migration->down());
        $sql = strtolower(str_replace(['"', '`'], '', implode("\n", array_column($create, 'query'))));
        foreach (['feature_audiences', 'feature_access_groups', 'feature_group_members', 'feature_audience_users', 'feature_audience_groups'] as $table) {
            $this->assertStringContainsString('create table '.$table, $sql);
        }
        $this->assertStringContainsString('on delete cascade', $sql);
        $this->assertMatchesRegularExpression('/references users\s*\(id\)/', $sql);
        $this->assertStringContainsString('(user_id, feature)', $sql);
        $this->assertStringContainsString('(user_id, group_id)', $sql);
        $this->assertCount(5, $drop);
        $this->assertStringContainsString('feature_audience_groups', $drop[0]['query']);
        $this->assertStringContainsString('feature_audiences', $drop[4]['query']);
        $inserts = array_values(array_filter($create, fn ($query) => str_starts_with($query['query'], 'insert')));
        $this->assertCount(4, $inserts);
        $this->assertSame(['flashcardsEnabled', 0], $inserts[0]['bindings']);
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
