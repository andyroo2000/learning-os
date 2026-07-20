<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\RepairLegacyStudyMediaReferencesAction;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepairLegacyStudyMediaReferencesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_scan_repairs_every_keyset_chunk(): void
    {
        $this->app->bind(
            RepairLegacyStudyMediaReferencesAction::class,
            fn (): RepairLegacyStudyMediaReferencesAction => new RepairLegacyStudyMediaReferencesAction(
                app(RecordSyncFeedEntryAction::class),
                chunkSize: 2,
            ),
        );
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $cards = collect(range(1, 5))->map(function (int $index) use ($deck, $user): Card {
            $filename = "chunk-{$index}.mp3";
            $legacyId = sprintf('7ff08851-1396-4960-8cfe-%012d', $index);
            $card = Card::factory()->for($deck)->create([
                'prompt_json' => [
                    'cueAudio' => $this->legacyReference('audio', $filename, $legacyId),
                ],
            ]);
            $card->mediaAssets()->attach($this->mediaFor($user, 'audio/mpeg', $filename));

            return $card;
        });

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 5 linked cards scanned, 5 cards changed, 5 references changed.')
            ->assertExitCode(0);

        $cards->each(function (Card $card): void {
            $card->refresh();
            $this->assertTrue(Str::isUlid($card->prompt_json['cueAudio']['id']));
            $this->assertSame(
                "/api/study/media/{$card->prompt_json['cueAudio']['id']}",
                $card->prompt_json['cueAudio']['url'],
            );
        });
        $this->assertDatabaseCount('sync_feed_entries', 5);
    }

    public function test_dry_run_reports_repairs_without_changing_cards(): void
    {
        [$card, $audio, $image] = $this->legacyCardWithLinkedMedia();
        $originalUpdatedAt = DB::table('cards')->where('id', $card->id)->value('updated_at');

        $this->artisan('study:repair-legacy-media-references')
            ->expectsOutputToContain('Dry run completed: 1 linked cards scanned, 1 cards changed, 2 references changed.')
            ->expectsOutputToContain('0 stale references were unmatched; 0 were ambiguous and left unchanged.')
            ->assertExitCode(0);

        $card->refresh();
        $this->assertSame('7ff08851-1396-4960-8cfe-cb3c348092ce', $card->prompt_json['cueAudio']['id']);
        $this->assertSame('72168550-039e-4624-9f9b-abd30405ac21', $card->answer_json['nested']['answerImage']['id']);
        $this->assertSame($originalUpdatedAt, DB::table('cards')->where('id', $card->id)->value('updated_at'));
        $this->assertNotSame($audio->id, $card->prompt_json['cueAudio']['id']);
        $this->assertNotSame($image->id, $card->answer_json['nested']['answerImage']['id']);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_apply_repairs_nested_audio_and_image_references_idempotently(): void
    {
        [$card, $audio, $image] = $this->legacyCardWithLinkedMedia();
        $originalUpdatedAt = DB::table('cards')->where('id', $card->id)->value('updated_at');

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 1 linked cards scanned, 1 cards changed, 2 references changed.')
            ->assertExitCode(0);

        $card->refresh();
        $this->assertSame($audio->id, $card->prompt_json['cueAudio']['id']);
        $this->assertSame("/api/study/media/{$audio->id}", $card->prompt_json['cueAudio']['url']);
        $this->assertSame($image->id, $card->answer_json['nested']['answerImage']['id']);
        $this->assertSame("/api/study/media/{$image->id}", $card->answer_json['nested']['answerImage']['url']);
        $this->assertSame($originalUpdatedAt, DB::table('cards')->where('id', $card->id)->value('updated_at'));
        $syncEntry = SyncFeedEntry::query()
            ->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();
        $this->assertSame($card->ownerUserId(), $syncEntry->user_id);
        $this->assertSame(SyncFeedOperation::Update, $syncEntry->operation);
        $this->assertSame($card->prompt_json, $syncEntry->payload['prompt_json']);
        $this->assertSame($card->answer_json, $syncEntry->payload['answer_json']);
        $this->assertSame($card->updated_at->toJSON(), $syncEntry->payload['updated_at']);

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 1 linked cards scanned, 0 cards changed, 0 references changed.')
            ->assertExitCode(0);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_apply_repairs_disagreeing_current_media_ids_and_urls(): void
    {
        $user = User::factory()->create();
        $media = $this->mediaFor($user, 'audio/mpeg', 'current.mp3');
        $wrongMediaId = (string) Str::ulid();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'prompt_json' => [
                'cueAudio' => [
                    'id' => $media->id,
                    'filename' => 'current.mp3',
                    'url' => "/api/study/media/{$wrongMediaId}",
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
            ],
        ]);
        $card->mediaAssets()->attach($media);

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 1 linked cards scanned, 1 cards changed, 1 references changed.')
            ->assertExitCode(0);

        $card->refresh();
        $this->assertSame($media->id, $card->prompt_json['cueAudio']['id']);
        $this->assertSame("/api/study/media/{$media->id}", $card->prompt_json['cueAudio']['url']);
    }

    public function test_apply_repairs_a_filename_mismatch_when_only_one_linked_asset_has_the_same_kind(): void
    {
        $user = User::factory()->create();
        $media = $this->mediaFor($user, 'audio/mpeg', 'generated-card-id.mp3');
        $legacyId = 'e7b3e62f-33ec-4b54-99d0-ddfec07d80a0';
        $card = Card::factory()->for($this->deckFor($user))->create([
            'prompt_json' => [
                'cueAudio' => $this->legacyReference(
                    'audio',
                    'manual-draft-audio.mp3',
                    $legacyId,
                ),
            ],
        ]);
        $card->mediaAssets()->attach($media);

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 1 linked cards scanned, 1 cards changed, 1 references changed.')
            ->assertExitCode(0);

        $card->refresh();
        $this->assertSame($media->id, $card->prompt_json['cueAudio']['id']);
        $this->assertSame("/api/study/media/{$media->id}", $card->prompt_json['cueAudio']['url']);
    }

    public function test_apply_leaves_a_filename_mismatch_ambiguous_with_multiple_same_kind_assets(): void
    {
        $user = User::factory()->create();
        $legacyId = 'e7b3e62f-33ec-4b54-99d0-ddfec07d80a0';
        $card = Card::factory()->for($this->deckFor($user))->create([
            'prompt_json' => [
                'cueAudio' => $this->legacyReference(
                    'audio',
                    'manual-draft-audio.mp3',
                    $legacyId,
                ),
            ],
        ]);
        $card->mediaAssets()->attach([
            $this->mediaFor($user, 'audio/mpeg', 'first-generated.mp3')->id,
            $this->mediaFor($user, 'audio/mpeg', 'second-generated.mp3')->id,
        ]);

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 1 linked cards scanned, 0 cards changed, 0 references changed.')
            ->expectsOutputToContain('0 stale references were unmatched; 1 were ambiguous and left unchanged.')
            ->assertExitCode(0);

        $this->assertSame($legacyId, $card->refresh()->prompt_json['cueAudio']['id']);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_apply_leaves_ambiguous_and_unmatched_references_unchanged(): void
    {
        $user = User::factory()->create();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'prompt_json' => [
                'cueAudio' => $this->legacyReference(
                    'audio',
                    'duplicate.mp3',
                    '7ff08851-1396-4960-8cfe-cb3c348092ce',
                ),
            ],
            'answer_json' => [
                'answerImage' => $this->legacyReference(
                    'image',
                    'unlinked.png',
                    '72168550-039e-4624-9f9b-abd30405ac21',
                ),
            ],
        ]);
        $first = $this->mediaFor($user, 'audio/mpeg', 'duplicate.mp3', 'study-media/user/first.mp3');
        $second = $this->mediaFor($user, 'audio/mpeg', 'duplicate.mp3', 'study-media/user/second.mp3');
        $card->mediaAssets()->attach([$first->id, $second->id]);

        $this->artisan('study:repair-legacy-media-references', ['--apply' => true])
            ->expectsOutputToContain('Repair completed: 1 linked cards scanned, 0 cards changed, 0 references changed.')
            ->expectsOutputToContain('1 stale references were unmatched; 1 were ambiguous and left unchanged.')
            ->assertExitCode(0);

        $card->refresh();
        $this->assertSame(
            '7ff08851-1396-4960-8cfe-cb3c348092ce',
            $card->prompt_json['cueAudio']['id'],
        );
        $this->assertSame(
            '72168550-039e-4624-9f9b-abd30405ac21',
            $card->answer_json['answerImage']['id'],
        );
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    /**
     * @return array{Card, MediaAsset, MediaAsset}
     */
    private function legacyCardWithLinkedMedia(): array
    {
        $user = User::factory()->create();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'prompt_json' => [
                'cueAudio' => $this->legacyReference(
                    'audio',
                    'word & tone.mp3',
                    '7ff08851-1396-4960-8cfe-cb3c348092ce',
                ),
            ],
            'answer_json' => [
                'nested' => [
                    'answerImage' => $this->legacyReference(
                        'image',
                        'company.png',
                        '72168550-039e-4624-9f9b-abd30405ac21',
                    ),
                ],
            ],
            'updated_at' => '2026-07-19 10:00:00',
        ]);
        $audio = $this->mediaFor($user, 'audio/mpeg', 'word & tone.mp3');
        $image = $this->mediaFor($user, 'image/png', 'company.png');
        $card->mediaAssets()->attach([$audio->id, $image->id]);

        return [$card, $audio, $image];
    }

    private function mediaFor(
        User $user,
        string $mimeType,
        string $filename,
        ?string $path = null,
    ): MediaAsset {
        return MediaAsset::factory()->for($user)->create([
            'path' => $path ?? "study-media/user/{$filename}",
            'mime_type' => $mimeType,
            'original_filename' => $filename,
            'source_filename' => $filename,
        ]);
    }

    /**
     * @return array{id: string, filename: string, url: string, mediaKind: string, source: string}
     */
    private function legacyReference(string $kind, string $filename, string $id): array
    {
        return [
            'id' => $id,
            'filename' => $filename,
            'url' => "/api/study/media/{$id}",
            'mediaKind' => $kind,
            'source' => $kind === 'audio' ? 'imported' : 'imported_image',
        ];
    }
}
