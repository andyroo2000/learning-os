<?php

namespace Tests\Feature\Content;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ContentGenerationQuotaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adoption_preserves_legacy_rows_and_adds_the_usage_index_when_missing(): void
    {
        Schema::drop('content_generation_cooldowns');
        Schema::drop('generation_logs');
        Schema::create('generation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('userId');
            $table->string('contentType');
            $table->string('contentId')->nullable();
            $table->timestampTz('createdAt', 3);
        });
        $id = (string) Str::uuid();
        DB::table('generation_logs')->insert([
            'id' => $id,
            'userId' => (string) Str::uuid(),
            'contentType' => 'dialogue',
            'contentId' => null,
            'createdAt' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_07_23_010000_adopt_generation_logs_and_create_cooldowns.php',
        );
        $migration->up();
        $migration->down();

        $this->assertDatabaseHas('generation_logs', ['id' => $id]);
        $usageIndex = collect(Schema::getIndexes('generation_logs'))->first(
            fn (array $index): bool => array_map(
                static fn (string $column): string => strtolower($column),
                $index['columns'],
            ) === ['userid', 'createdat'],
        );
        $this->assertNotNull($usageIndex);
        $this->assertFalse(Schema::hasTable('content_generation_cooldowns'));
    }

    public function test_adoption_rejects_a_legacy_table_missing_required_columns(): void
    {
        Schema::drop('content_generation_cooldowns');
        Schema::drop('generation_logs');
        Schema::create('generation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('userId');
        });
        $migration = require database_path(
            'migrations/2026_07_23_010000_adopt_generation_logs_and_create_cooldowns.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot adopt generation_logs table; missing columns: contentType, contentId, createdAt.',
        );

        $migration->up();
    }
}
