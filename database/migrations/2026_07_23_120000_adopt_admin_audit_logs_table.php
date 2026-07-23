<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUIRED_COLUMNS = [
        'id',
        'adminUserId',
        'action',
        'targetUserId',
        'metadata',
        'ipAddress',
        'userAgent',
        'createdAt',
    ];

    public function up(): void
    {
        if (Schema::hasTable('admin_audit_logs')) {
            $missingColumns = array_values(array_diff(
                self::REQUIRED_COLUMNS,
                Schema::getColumnListing('admin_audit_logs'),
            ));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Cannot adopt admin_audit_logs table; missing columns: '
                    .implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('adminUserId')->index('admin_audit_logs_adminUserId_idx');
            $table->string('action')->index('admin_audit_logs_action_idx');
            $table->string('targetUserId')->nullable();
            $table->json('metadata')->nullable();
            $table->text('ipAddress')->nullable();
            $table->text('userAgent')->nullable();
            $table->timestamp('createdAt', 3)->useCurrent()
                ->index('admin_audit_logs_createdAt_idx');
        });
    }

    public function down(): void
    {
        // A restored Convo Lab database may own this table. Keep it on rollback because a
        // later process cannot know whether up() adopted existing data or created the table.
        // This intentionally leaves fresh-install tables too; migrate:fresh is the safe reset.
    }
};
