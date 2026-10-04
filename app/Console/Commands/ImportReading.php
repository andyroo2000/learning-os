<?php

namespace App\Console\Commands;

use App\Domain\Readings\Actions\StoreReadingImage;
use App\Domain\Readings\Data\ReadingDocument;
use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class ImportReading extends Command
{
    protected $signature = 'readings:import {file : JSON document} {--user= : Owner email} {--slug= : Stable book identifier} {--illustration= : Local JPEG or PNG}';

    protected $description = 'Import a private reading into one user account';

    public function handle(StoreReadingImage $images): int
    {
        $user = User::query()->where('email', $this->option('user'))->firstOrFail();
        $json = file_get_contents($this->argument('file'));
        $document = ReadingDocument::validate(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $slug = $this->option('slug') ?: Str::slug($document['title']);
        if (! is_string($slug) || ! preg_match('/^.{1,200}$/u', $slug)) {
            throw new RuntimeException('Provide a nonempty --slug up to 200 characters.');
        }
        $reading = Reading::query()->firstOrNew(['user_id' => $user->id, 'slug' => $slug]);
        $reading->id ??= $reading->newUniqueId();
        $reading->document = $this->importPageImages($reading, $document, $images);
        $this->importIllustration($reading, $images);
        $reading->save();
        $this->info('Imported '.$reading->id.' ('.$document['title'].')');

        return self::SUCCESS;
    }

    private function importIllustration(Reading $reading, StoreReadingImage $images): void
    {
        $file = $this->option('illustration');
        if (! is_string($file) || $file === '') {
            return;
        }
        $reading->illustration_path = $images->handle($reading->id, $file);
    }

    private function importPageImages(Reading $reading, array $document, StoreReadingImage $images): array
    {
        foreach ($document['pages'] as &$page) {
            if (isset($page['imageFile'])) {
                $page['imagePath'] = $images->handle($reading->id, $page['imageFile']);
                unset($page['imageFile']);
            }
        }

        return $document;
    }
}
