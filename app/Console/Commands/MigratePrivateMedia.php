<?php

namespace App\Console\Commands;

use App\Support\Storage\VerifiedMediaCopy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MigratePrivateMedia extends Command
{
    protected $signature = 'media:migrate-to-gcs
        {--verify-only : Check remote copies without uploading}
        {--delete-local : Delete verified source files after the application switches to GCS}';

    protected $description = 'Copy and checksum-verify persistent private and media disks in Google Cloud Storage';

    public function handle(VerifiedMediaCopy $copy): int
    {
        $this->validateDeletion();
        $count = 0;
        $bytes = 0;
        foreach (['local', 'media'] as $name) {
            $config = config("filesystems.disks.{$name}");
            $source = Storage::build(['driver' => 'local', 'root' => $config['root'], 'throw' => true]);
            $target = Storage::build([...$config, 'driver' => 'gcs-private', 'throw' => true]);
            foreach ($source->getDriver()->listContents('', true) as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = $file->path();
                $bytes += $copy->copy($source, $target, $path, (bool) $this->option('verify-only'));
                if ($this->option('delete-local') && ! $source->delete($path)) {
                    throw new RuntimeException("Could not remove verified source: {$path}");
                }
                $count++;
                if ($count % 100 === 0) {
                    $this->line("Verified {$count} files ({$bytes} bytes).");
                }
            }
        }
        $this->info("Verified {$count} files ({$bytes} bytes). Local deletion: ".($this->option('delete-local') ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function validateDeletion(): void
    {
        if (! $this->option('delete-local')) {
            return;
        }
        foreach (['local', 'media'] as $name) {
            if (config("filesystems.disks.{$name}.driver") !== 'gcs-private') {
                throw new RuntimeException('Switch all writers to gcs-private before deleting local files.');
            }
        }
        if (! $this->option('verify-only')) {
            throw new RuntimeException('Deletion requires --verify-only; missing remote files must never be recreated during cleanup.');
        }
    }
}
