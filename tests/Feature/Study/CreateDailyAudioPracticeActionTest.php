<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CreateDailyAudioPracticeAction;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateDailyAudioPracticeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_practice_and_runs_the_lifecycle_callback_after_commit(): void
    {
        $user = User::factory()->create();
        $dispatchedId = null;

        $practice = app(CreateDailyAudioPracticeAction::class)->handle(
            $user->id,
            '2026-07-19',
            DailyAudioPracticeGeneration::DEFAULT_TARGET_DURATION_MINUTES,
            afterCommit: static function (string $practiceId) use (&$dispatchedId): void {
                $dispatchedId = $practiceId;
            },
        );

        $this->assertSame($practice->id, $dispatchedId);
        $this->assertSame('generating', $practice->status);
        $this->assertCount(3, $practice->tracks);
    }

    #[DataProvider('invalidInputProvider')]
    public function test_it_rejects_invalid_direct_action_input_before_writing(
        int $userId,
        string $practiceDate,
        int $duration,
    ): void {
        $callbackCalled = false;

        try {
            app(CreateDailyAudioPracticeAction::class)->handle(
                $userId,
                $practiceDate,
                $duration,
                afterCommit: static function () use (&$callbackCalled): void {
                    $callbackCalled = true;
                },
            );
            $this->fail('Expected invalid Daily Audio Practice input to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('daily_audio_practices', 0);
            $this->assertDatabaseCount('daily_audio_practice_tracks', 0);
            $this->assertFalse($callbackCalled);
        }
    }

    public static function invalidInputProvider(): array
    {
        return [
            'missing user' => [0, '2026-07-19', 30],
            'negative user' => [-1, '2026-07-19', 30],
            'invalid date' => [1, '2026-02-30', 30],
            'date with timestamp' => [1, '2026-07-19T00:00:00Z', 30],
            'blank date' => [1, '', 30],
            'below minimum duration' => [1, '2026-07-19', 4],
            'above maximum duration' => [1, '2026-07-19', 61],
        ];
    }
}
