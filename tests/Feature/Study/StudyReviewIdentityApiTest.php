<?php

namespace Tests\Feature\Study;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudyReviewIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidClientReviewIdentityPairs(): iterable
    {
        $clientReviewId = strtolower((string) Str::ulid());

        yield 'missing reviewedAt' => [
            ['clientReviewId' => $clientReviewId],
            'reviewedAt',
        ];
        yield 'null reviewedAt with clientReviewId' => [
            ['clientReviewId' => $clientReviewId, 'reviewedAt' => null],
            'reviewedAt',
        ];
        yield 'missing clientReviewId' => [
            ['reviewedAt' => '2026-06-05T15:30:00Z'],
            'clientReviewId',
        ];
        yield 'null clientReviewId with reviewedAt' => [
            ['clientReviewId' => null, 'reviewedAt' => '2026-06-05T15:30:00Z'],
            'clientReviewId',
        ];
        yield 'malformed clientReviewId' => [
            ['clientReviewId' => 'not-a-ulid', 'reviewedAt' => '2026-06-05T15:30:00Z'],
            'clientReviewId',
        ];
        yield 'blank clientReviewId' => [
            ['clientReviewId' => " \t\n ", 'reviewedAt' => '2026-06-05T15:30:00Z'],
            'clientReviewId',
        ];
        yield 'array clientReviewId' => [
            ['clientReviewId' => [$clientReviewId], 'reviewedAt' => '2026-06-05T15:30:00Z'],
            'clientReviewId',
        ];
        yield 'relative reviewedAt' => [
            ['clientReviewId' => $clientReviewId, 'reviewedAt' => 'tomorrow'],
            'reviewedAt',
        ];
        yield 'timezone-naive reviewedAt' => [
            ['clientReviewId' => $clientReviewId, 'reviewedAt' => '2026-06-05T15:30:00'],
            'reviewedAt',
        ];
        yield 'numeric reviewedAt' => [
            ['clientReviewId' => $clientReviewId, 'reviewedAt' => 1_780_678_200],
            'reviewedAt',
        ];
        yield 'array reviewedAt' => [
            ['clientReviewId' => $clientReviewId, 'reviewedAt' => ['2026-06-05T15:30:00Z']],
            'reviewedAt',
        ];
    }

    /** @param array<string, mixed> $identity */
    #[DataProvider('invalidClientReviewIdentityPairs')]
    public function test_it_requires_and_validates_the_client_review_identity_pair(
        array $identity,
        string $expectedError,
    ): void {
        $this->withoutMiddleware(TrimStrings::class);
        $card = $this->cardFor($this->signIn());

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            ...$identity,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$expectedError]);

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_legacy_review_clients_may_omit_or_null_the_client_identity_pair(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            $firstCard = $this->cardFor($user);
            $secondCard = $this->cardFor($user);

            $omittedResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $firstCard->id,
                'grade' => 'good',
            ]);
            $nullResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $secondCard->id,
                'grade' => 'good',
                'clientReviewId' => null,
                'reviewedAt' => null,
            ]);

            $omittedResponse->assertOk();
            $nullResponse->assertOk();
            $this->assertIsString($omittedResponse->json('reviewLogId'));
            $this->assertIsString($nullResponse->json('reviewLogId'));
            $this->assertNotSame($omittedResponse->json('reviewLogId'), $nullResponse->json('reviewLogId'));
            $this->assertDatabaseCount('card_review_events', 2);
            $this->assertSame(1, $firstCard->refresh()->scheduler_state['reps']);
            $this->assertSame(1, $secondCard->refresh()->scheduler_state['reps']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
