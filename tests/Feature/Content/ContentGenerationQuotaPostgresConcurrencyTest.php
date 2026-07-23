<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Enums\ContentGenerationType;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContentGenerationQuotaPostgresConcurrencyTest extends TestCase
{
    private const LOCK_CONNECTION = 'generation_quota_lock_test';

    public function test_shared_account_lock_serializes_overlapping_reservations(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL row-lock coverage runs in the PostgreSQL CI job.');
        }

        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->convoLabProjectionFor($user, $convoLabUserId);
        $holder = $this->lockConnection();
        $process = $this->reservationProcess($convoLabUserId);

        try {
            $holder->beginTransaction();
            $holder->table('admin_user_projections')
                ->where('convolab_id', $convoLabUserId)
                ->lockForUpdate()
                ->sole();

            $process->start();
            $this->assertTrue(
                $this->waitForReservationLock($process),
                "The overlapping reservation never waited on the account row lock.\n".$process->getErrorOutput(),
            );

            $holder->commit();
            $process->wait();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertDatabaseHas('generation_logs', [
                'id' => trim($process->getOutput()),
                'userId' => $convoLabUserId,
                'contentType' => ContentGenerationType::Course->value,
            ]);
            $this->assertDatabaseHas('content_generation_cooldowns', [
                'convolab_user_id' => $convoLabUserId,
                'generation_log_id' => trim($process->getOutput()),
            ]);
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }

            DB::purge(self::LOCK_CONNECTION);
            DB::table('content_generation_cooldowns')
                ->where('convolab_user_id', $convoLabUserId)
                ->delete();
            DB::table('generation_logs')->where('userId', $convoLabUserId)->delete();
            DB::table('admin_user_projections')->where('convolab_id', $convoLabUserId)->delete();
            $user->delete();
        }
    }

    private function lockConnection(): Connection
    {
        $defaultConnection = DB::getDefaultConnection();
        config([
            'database.connections.'.self::LOCK_CONNECTION => config(
                "database.connections.{$defaultConnection}",
            ),
        ]);
        DB::purge(self::LOCK_CONNECTION);

        return DB::connection(self::LOCK_CONNECTION);
    }

    private function reservationProcess(string $convoLabUserId): Process
    {
        $code = sprintf(
            <<<'PHP'
require %s;
$app = require %s;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$reservation = $app->make(\App\Domain\Content\Actions\ManageContentGenerationQuotaAction::class)
    ->reserve(%s, \App\Domain\Content\Enums\ContentGenerationType::Course);
fwrite(STDOUT, (string) $reservation?->getKey());
PHP,
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
            var_export($convoLabUserId, true),
        );

        return new Process([PHP_BINARY, '-r', $code], base_path(), timeout: 10);
    }

    private function waitForReservationLock(Process $process): bool
    {
        $deadline = microtime(true) + 5;

        do {
            if (! $process->isRunning()) {
                return false;
            }

            $waiting = DB::selectOne(<<<'SQL'
                select case when exists (
                    select 1
                    from pg_stat_activity
                    where datname = current_database()
                      and pid <> pg_backend_pid()
                      and wait_event_type = 'Lock'
                      and query ilike '%admin_user_projections%'
                ) then 1 else 0 end as waiting
                SQL);
            if ($waiting !== null && (int) $waiting->waiting === 1) {
                return true;
            }

            usleep(25_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
