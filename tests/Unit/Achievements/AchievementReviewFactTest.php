<?php

namespace Tests\Unit\Achievements;

use App\Domain\Achievements\Values\AchievementReviewFact;
use App\Domain\Reviews\Enums\CardReviewRating;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AchievementReviewFactTest extends TestCase
{
    #[DataProvider('schedulerStates')]
    public function test_it_preserves_numeric_stability_semantics(?string $state, float $expected): void
    {
        $fact = new AchievementReviewFact((object) [
            'id' => 'review-id',
            'card_id' => 'card-id',
            'rating' => 'good',
            'reviewed_at' => '2026-10-15 18:51:58.123456+00',
            'created_at' => '2026-10-15 18:52:00.654321+00',
            'scheduler_state_after' => $state,
        ]);

        $this->assertSame('review-id', $fact->id);
        $this->assertSame('card-id', $fact->cardId);
        $this->assertSame(CardReviewRating::Good, $fact->rating);
        $this->assertSame('2026-10-15T18:51:58.123456+00:00', $fact->reviewedAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-10-15T18:52:00.654321+00:00', $fact->createdAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame($expected, $fact->stability);
    }

    public static function schedulerStates(): array
    {
        return [
            'SQL null' => [null, 0.0],
            'JSON null' => ['null', 0.0],
            'missing stability' => ['{}', 0.0],
            'integer' => ['{"stability":365}', 365.0],
            'decimal' => ['{"stability":7.5}', 7.5],
            'numeric string' => ['{"stability":"365"}', 0.0],
            'boolean' => ['{"stability":true}', 0.0],
            'array' => ['{"stability":[]}', 0.0],
        ];
    }
}
