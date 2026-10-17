<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Keep these historical files when pruning or squashing migrations: this
    // retirement migration uses them for both forward and rollback schemas.
    private const SCHEMA_MIGRATIONS = [
        '2026_08_25_210000_create_study_milestone_tables.php',
        '2026_08_27_120000_create_achievement_awards_table.php',
        '2026_08_31_120000_create_achievement_progress_projections.php',
    ];

    public function up(): void
    {
        // Historical migrations remain the immutable schema definition. Reverse
        // their operations to remove the award tables and projection-only indexes.
        foreach (array_reverse(self::SCHEMA_MIGRATIONS) as $file) {
            (require __DIR__.'/'.$file)->down();
        }
    }

    public function down(): void
    {
        // Rollback restores empty tables; retired award history is not recovered.
        foreach (self::SCHEMA_MIGRATIONS as $file) {
            (require __DIR__.'/'.$file)->up();
        }
    }
};
