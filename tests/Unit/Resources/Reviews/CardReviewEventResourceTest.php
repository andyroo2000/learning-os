<?php

namespace Tests\Unit\Resources\Reviews;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudyImportJob;
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

    public function test_review_event_resource_serializes_import_source_metadata(): void
    {
        $reviewEvent = new CardReviewEvent;
        $reviewEvent->setRawAttributes([
            'id' => '01jzq4tvb2sbc5ab6b0n3thhay',
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'card_deck_id' => null,
            'card_course_id' => null,
            'import_job_id' => '01k1j8n4st9y2aqj9b43r1dz0e',
            'source_kind' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_review_id' => 901,
            'source_card_id' => 701,
            'source_ease' => 3,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => 980,
            'source_review_type' => 1,
            'raw_payload_json' => json_encode([
                'source_review_id' => 901,
                'source_card_id' => 701,
            ]),
            'rating' => 'good',
            'reviewed_at' => null,
        ], sync: true);

        $resource = CardReviewEventResource::make($reviewEvent)->resolve();

        $this->assertSame('01k1j8n4st9y2aqj9b43r1dz0e', $resource['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $resource['source_kind']);
        $this->assertSame(901, $resource['source_review_id']);
        $this->assertSame(701, $resource['source_card_id']);
        $this->assertSame(3, $resource['source_ease']);
        $this->assertSame(12, $resource['source_interval']);
        $this->assertSame(6, $resource['source_last_interval']);
        $this->assertSame(2500, $resource['source_factor']);
        $this->assertSame(980, $resource['source_time_ms']);
        $this->assertSame(1, $resource['source_review_type']);
        $this->assertArrayNotHasKey('raw_payload_json', $resource);
    }
}
