<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MYSQL_UNSIGNED_INTEGER_MAX = 4_294_967_295;

    private const SIGNED_INTEGER_MIN = -2_147_483_648;

    private const SIGNED_INTEGER_MAX = 2_147_483_647;

    public function up(): void
    {
        // SQLite INTEGER columns are already signed 64-bit values regardless of the
        // Laravel unsignedInteger declaration used when the column was introduced.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('cards', function (Blueprint $table): void {
            $table->bigInteger('new_queue_position')->nullable()->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $minimum = DB::table('cards')->whereNotNull('new_queue_position')->min('new_queue_position');
        $maximum = DB::table('cards')->whereNotNull('new_queue_position')->max('new_queue_position');

        if ($minimum !== null && $maximum !== null) {
            $minimum = (int) $minimum;
            $maximum = (int) $maximum;

            if ($driver === 'mysql' && $minimum < 0) {
                $offset = -$minimum;

                if ($maximum > self::MYSQL_UNSIGNED_INTEGER_MAX - $offset) {
                    throw new RuntimeException('New-card queue positions cannot fit the rollback column.');
                }

                // Rollbacks are rare and may rewrite the queue column, but one set-based
                // shift preserves every user's relative order before restoring unsigned storage.
                DB::table('cards')
                    ->whereNotNull('new_queue_position')
                    ->increment('new_queue_position', $offset);
            }

            if ($driver === 'pgsql'
                && ($minimum < self::SIGNED_INTEGER_MIN || $maximum > self::SIGNED_INTEGER_MAX)) {
                throw new RuntimeException('New-card queue positions cannot fit the rollback column.');
            }
        }

        Schema::table('cards', function (Blueprint $table): void {
            $table->unsignedInteger('new_queue_position')->nullable()->change();
        });
    }
};
