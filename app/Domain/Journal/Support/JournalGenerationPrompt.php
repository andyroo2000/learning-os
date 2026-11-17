<?php

namespace App\Domain\Journal\Support;

use Illuminate\Support\Facades\File;

final class JournalGenerationPrompt
{
    public static function feedback(): string
    {
        return self::read('feedback')."\n\n".self::read('compact-contract');
    }

    public static function combined(): string
    {
        return self::read('clarification')."\n\n".self::feedback()."\n\n".self::read('combined-contract');
    }

    public static function cacheKey(string $purpose): string
    {
        return 'journal-'.$purpose.'-v1';
    }

    private static function read(string $name): string
    {
        return File::get(resource_path('prompts/journal/'.$name.'.txt'));
    }
}
