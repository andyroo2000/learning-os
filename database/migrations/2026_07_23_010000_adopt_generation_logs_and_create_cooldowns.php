<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUIRED_LOG_COLUMNS = [
        'id',
        'userId',
        'contentType',
        'contentId',
        'createdAt',
    ];

    public function up(): void
    {
        if (Schema::hasTable('generation_logs')) {
            $missingColumns = array_values(array_diff(
                self::REQUIRED_LOG_COLUMNS,
                Schema::getColumnListing('generation_logs'),
            ));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Cannot adopt generation_logs table; missing columns: '.implode(', ', $missingColumns).'.',
                );
            }

            $hasUsageIndex = collect(Schema::getIndexes('generation_logs'))
                ->contains(function (array $index): bool {
                    $columns = array_map(
                        static fn (string $column): string => strtolower($column),
                        $index['columns'],
                    );

                    return $columns === ['userid', 'createdat'];
                });
            if (! $hasUsageIndex) {
                Schema::table('generation_logs', function (Blueprint $table): void {
                    $table->index(['userId', 'createdAt'], 'generation_logs_user_created_idx');
                });
            }
        } else {
            Schema::create('generation_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('userId');
                $table->string('contentType');
                $table->string('contentId')->nullable();
                $table->timestampTz('createdAt', 3);
                $table->index('userId', 'generation_logs_user_id_idx');
                $table->index(['userId', 'createdAt'], 'generation_logs_user_created_idx');
                $table->index('createdAt', 'generation_logs_created_at_idx');
            });
        }

        Schema::create('content_generation_cooldowns', function (Blueprint $table): void {
            $table->uuid('convolab_user_id')->primary();
            // Cleanup always starts with the user primary key and compares this ownership token.
            $table->uuid('generation_log_id')->nullable();
            $table->timestampTz('available_at', 3);
            $table->foreign('convolab_user_id', 'generation_cooldowns_user_fk')
                ->references('convolab_id')
                ->on('admin_user_projections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_generation_cooldowns');

        // Rollbacks run in a later process and cannot know whether up() adopted or created
        // generation_logs. Retain the table and any compatibility index in both cases rather
        // than risk deleting source-system usage history from an adopted Convo Lab database.
    }
};
