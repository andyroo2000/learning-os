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
        // This table may predate Learning OS in a restored Convo Lab database.
        // Retaining it is safer than deleting source-owned data during rollback.
    }
};
