<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\RepairLegacyStudyMediaReferencesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
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

    public function test_direct_apply_persists_and_syncs_repaired_derived_fields(): void
    {
        $user = User::factory()->create();
        $legacyId = '7ff08851-1396-4960-8cfe-cb3c348092ce';
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '猫',
            'back_text' => 'cat',
            'prompt_json' => [
                'cueAudio' => [
                    'id' => $legacyId,
                    'filename' => 'cat.mp3',
                    'url' => "/api/study/media/{$legacyId}",
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
            ],
            'search_text' => 'stale search text',
            'content_revision' => 5,
            'updated_at' => '2026-07-19 10:00:00',
        ]);
        $media = MediaAsset::factory()->for($user)->create([
            'path' => 'study-media/user/cat.mp3',
            'mime_type' => 'audio/mpeg',
            'original_filename' => 'cat.mp3',
            'source_filename' => 'cat.mp3',
        ]);
        $card->mediaAssets()->attach($media);

        $result = app(RepairLegacyStudyMediaReferencesAction::class)->handle(
            DB::connection(),
            apply: true,
            cardIds: [$card->id],
        );

        $this->assertSame(1, $result->cardsChanged);
        $card->refresh();
        $this->assertSame($media->id, $card->prompt_json['cueAudio']['id']);
        $this->assertSame(6, $card->content_revision);
        $this->assertSame(
            CardSearchText::fromContent(
                frontText: $card->front_text,
                backText: $card->back_text,
                promptJson: $card->prompt_json,
                answerJson: $card->answer_json,
            ),
            $card->search_text,
        );
        $this->assertNotSame('2026-07-19T10:00:00.000000Z', $card->updated_at->toJSON());

        $entry = SyncFeedEntry::query()
            ->where('user_id', $user->id)
            ->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame($card->prompt_json, $entry->payload['prompt_json']);
        $this->assertSame(6, $entry->payload['content_revision']);
        $this->assertSame($card->search_text, $entry->payload['search_text']);
        $this->assertSame($card->updated_at->toJSON(), $entry->payload['updated_at']);
    }
}
