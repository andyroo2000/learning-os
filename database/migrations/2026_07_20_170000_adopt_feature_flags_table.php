<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUIRED_COLUMNS = [
        'id',
        'dialoguesEnabled',
        'scriptsEnabled',
        'audioCourseEnabled',
        'flashcardsEnabled',
        'updatedAt',
    ];

    public function up(): void
    {
        if (Schema::hasTable('feature_flags')) {
            // Convo Lab migrations remain authoritative for legacy column types and timestamp
            // precision; portable schema APIs do not report those types consistently by driver.
            $missingColumns = array_values(array_diff(
                self::REQUIRED_COLUMNS,
                Schema::getColumnListing('feature_flags'),
            ));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Cannot adopt feature_flags table; missing columns: '.implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->boolean('dialoguesEnabled')->default(true);
            $table->boolean('scriptsEnabled')->default(true);
            $table->boolean('audioCourseEnabled')->default(true);
            $table->boolean('flashcardsEnabled')->default(true);
            $table->timestamp('updatedAt', 3);
        });
    }

    public function down(): void
    {
        // Rollbacks run in a later process, so this migration cannot know whether up() adopted
        // or created the table. Always retain it rather than risk deleting source-owned data.
    }
};
