<?php

namespace Tests\Unit\Resources\Reviews;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Http\Resources\Reviews\CardReviewEventResource;
use Tests\TestCase;

class CardReviewEventResourceTest extends TestCase
{
    public function test_review_event_resource_preserves_raw_legacy_rating_values(): void
    {
        $reviewEvent = new CardReviewEvent;
        $reviewEvent->setRawAttributes([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'card_deck_id' => null,
            'card_course_id' => null,
            'rating' => 'legacy-rating',
            'reviewed_at' => null,
        ], sync: true);

        $resource = CardReviewEventResource::make($reviewEvent)->resolve();

        $this->assertSame('legacy-rating', $resource['rating']);
    }
}
