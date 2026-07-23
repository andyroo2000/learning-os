<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class AdminAuditLogMigrationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_down_intentionally_keeps_a_freshly_created_table_for_adoption_safety(): void
    {
        $migration = require database_path(
            'migrations/2026_07_23_120000_adopt_admin_audit_logs_table.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumns('admin_audit_logs', [
            'id',
            'adminUserId',
            'action',
            'targetUserId',
            'metadata',
            'ipAddress',
            'userAgent',
            'createdAt',
        ]));

        $migration->down();

        $this->assertTrue(Schema::hasTable('admin_audit_logs'));
    }

    public function test_migration_rejects_an_incomplete_existing_table(): void
    {
        Schema::drop('admin_audit_logs');
        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('adminUserId');
        });

        $migration = require database_path(
            'migrations/2026_07_23_120000_adopt_admin_audit_logs_table.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot adopt admin_audit_logs table; missing columns: '
            .'action, targetUserId, metadata, ipAddress, userAgent, createdAt.',
        );

        $migration->up();
    }
}
