<?php

namespace Tests\Unit\Journal;

use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\TestCase;

class JournalClarificationMigrationTest extends TestCase
{
    #[DataProviderExternal(JournalAnalysisMigrationTest::class, 'grammars')]
    public function test_migration_and_rollback_compile_for_supported_databases(string $connectionClass, string $grammarClass): void
    {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'test');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/2026_11_15_030000_create_journal_clarifications.php');
        $create = $connection->pretend(fn () => $migration->up());
        $drop = $connection->pretend(fn () => $migration->down());
        $sql = strtolower(str_replace(['"', '`'], '', implode("\n", array_column($create, 'query'))));
        $this->assertStringContainsString('create table journal_clarifications', $sql);
        $this->assertStringContainsString('on delete cascade', $sql);
        $this->assertStringContainsString('journal_clarifications_source_unique', $sql);
        $this->assertStringContainsString('(entry_id, source_version)', $sql);
        $this->assertLessThanOrEqual(63, strlen('journal_clarifications_source_unique'));
        $this->assertCount(1, $drop);
        $this->assertStringContainsString('drop table if exists', strtolower($drop[0]['query']));
        $this->assertStringContainsString('journal_clarifications', $drop[0]['query']);
    }
}
