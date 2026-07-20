<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\RepairLegacyStudyMediaReferencesAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairLegacyStudyMediaReferencesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_only_media_owned_by_the_card_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $legacyId = '7ff08851-1396-4960-8cfe-cb3c348092ce';
        $card = Card::factory()->for($this->deckFor($owner))->create([
            'prompt_json' => [
                'cueAudio' => [
                    'id' => $legacyId,
                    'filename' => 'word.mp3',
                    'url' => "/api/study/media/{$legacyId}",
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
            ],
        ]);
        $crossOwnerMedia = MediaAsset::factory()->for($otherUser)->create([
            'path' => 'study-media/other-user/word.mp3',
            'mime_type' => 'audio/mpeg',
            'original_filename' => 'word.mp3',
            'source_filename' => 'word.mp3',
        ]);
        DB::table('card_media')->insert([
            'card_id' => $card->id,
            'media_asset_id' => $crossOwnerMedia->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(RepairLegacyStudyMediaReferencesAction::class)->handle(
            DB::connection(),
            apply: true,
            cardIds: [$card->id],
        );

        $this->assertSame(1, $result->cardsScanned);
        $this->assertSame(0, $result->cardsChanged);
        $this->assertSame(0, $result->referencesChanged);
        $this->assertSame(1, $result->unmatchedReferences);
        $this->assertSame(0, $result->ambiguousReferences);
        $this->assertSame($legacyId, $card->refresh()->prompt_json['cueAudio']['id']);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
