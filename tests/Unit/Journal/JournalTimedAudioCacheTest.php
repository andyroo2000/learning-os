<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Support\JournalTimedAudioCache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class JournalTimedAudioCacheTest extends TestCase
{
    public static function corruptPayloads(): array
    {
        return [
            ['{incomplete'], ['{}'], ['{"audioBase64":"","sentences":[{}]}'],
            ['{"audioBase64":"SUQz","sentences":[{"text":"test","start":-1,"end":1}]}'],
            ['{"audioBase64":"SUQz","sentences":[{"text":"test","start":"0","end":1}]}'],
            ['{"audioBase64":"SUQz","sentences":[{"text":"test","start":2,"end":1}]}'],
        ];
    }

    #[DataProvider('corruptPayloads')]
    public function test_corrupted_metadata_is_deleted_and_can_be_regenerated(string $payload): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('journal/test.timed.json', $payload);
        $this->assertNull(JournalTimedAudioCache::read('journal/test.timed.json'));
        Storage::disk('local')->assertMissing('journal/test.timed.json');
    }
}
