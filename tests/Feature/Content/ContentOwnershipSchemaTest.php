<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentOwnershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_ownership_schema_and_lock_seed_are_available(): void
    {
        foreach ([
            'content_episodes',
            'content_courses',
            'content_audio_script_media',
            'content_episode_courses',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'source_system'));
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'source_system');
            $this->assertFalse($column['nullable']);
            $this->assertNull($column['default']);
        }

        $this->assertTrue(Schema::hasTable('content_episode_tombstones'));
        $this->assertSame(
            ContentSourceSystem::CONVOLAB,
            DB::table('content_source_locks')->sole()->source_system,
        );
    }

    public function test_content_source_lock_requires_a_caller_owned_transaction(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('transactionLevel')->willReturn(0);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The Convo Lab content source lock requires an active transaction.');

        ContentSourceLock::acquireConvoLab($connection);
    }
}
