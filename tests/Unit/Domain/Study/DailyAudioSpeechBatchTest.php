<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Services\DailyAudioSpeechBatch;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use Closure;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class DailyAudioSpeechBatchTest extends TestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_reordering_keeps_cached_audio_and_a_throttle_reduces_the_remaining_concurrency(bool $throttled): void
    {
        $speech = $this->mock(FishAudioSpeechGenerator::class);
        $requests = [];
        $speech->shouldReceive('generateBatch')->times($throttled ? 1 : 2)
            ->andReturnUsing(function (array $lines, Closure $onThrottle) use (&$requests, $throttled): array {
                if ($throttled) {
                    $onThrottle();
                }

                return array_map(function (array $line) use (&$requests): string {
                    $requests[] = $line[0];

                    return 'ID3'.$line[0];
                }, $lines);
            });
        $speech->shouldReceive('generate')->times($throttled ? 3 : 1)
            ->andReturnUsing(function (string $text) use (&$requests): string {
                $requests[] = $text;

                return 'ID3'.$text;
            });
        $units = array_map(fn ($text) => DailyAudioScriptUnit::narration($text, 'voice'), ['a', 'b', 'c', 'd', 'e', 'a']);
        $batch = new DailyAudioSpeechBatch($speech, $units, null);
        foreach (['c', 'b', 'a', 'd', 'e'] as $text) {
            $this->assertSame('ID3'.$text, $batch->generate($text, 'voice'));
        }
        sort($requests);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $requests);
    }
}
