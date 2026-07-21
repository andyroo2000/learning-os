<?php

namespace App\Console\Commands;

use App\Console\Concerns\ConnectsToConvoLabSource;
use App\Domain\Admin\Actions\SyncConvoLabAdminProjectionAction;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncConvoLabAdminProjection extends Command
{
    use ConnectsToConvoLabSource;

    protected $signature = 'admin:sync-convolab
        {--source-connection=convolab_admin : Temporary source connection name}
        {--source-database= : Convo Lab source database name}
        {--source-host= : Source database host; defaults to DB_HOST}
        {--source-port= : Source database port; defaults to DB_PORT}
        {--source-username= : Source database username; defaults to DB_USERNAME}
        {--source-password= : Source database password; defaults to DB_PASSWORD}
        {--allow-empty-source : Confirm removal when a source table is empty and its projection is not}
        {--allow-production : Permit the sync to run when APP_ENV=production}';

    protected $description = 'Synchronize Convo Lab users and invite codes into the Learning OS admin projection.';

    public function handle(SyncConvoLabAdminProjectionAction $action): int
    {
        if (app()->isProduction() && ! $this->option('allow-production')) {
            $this->error('This command must not run in production without --allow-production.');

            return self::FAILURE;
        }

        try {
            $target = DB::connection();
            $source = $this->sourceConnection();
            if ($this->sameDatabase($source, $target)) {
                throw new \RuntimeException('Source and target databases must differ.');
            }

            $result = $target->transaction(function () use ($action, $source, $target) {
                ContentSourceLock::acquireConvoLab($target);

                return $action->handle(
                    $source,
                    $target,
                    (bool) $this->option('allow-empty-source'),
                );
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Synchronized {$result->users} users and {$result->inviteCodes} invite codes.");

        return self::SUCCESS;
    }
}
