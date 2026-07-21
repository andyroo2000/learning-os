<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\CreateContentCourseAction;
use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateContentCourseActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_learning_owned_course_from_normalized_owned_episodes(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $first = $this->episodeFor($user, $sourceUserId, ContentSourceSystem::LEARNING_OS);
        $second = $this->episodeFor($user, $sourceUserId, ContentSourceSystem::CONVOLAB);

        $result = app(CreateContentCourseAction::class)->handle(CreateContentCourseData::fromInput(
            $user->id,
            '  '.strtoupper($sourceUserId).'  ',
            [
                ...$this->baseInput(),
                'title' => '  Direct Course  ',
                'description' => '  Direct description.  ',
                'episodeIds' => [strtoupper($second->id), $first->id],
            ],
        ));

        $this->assertTrue($result->episodesFound);
        $this->assertNotNull($result->course);
        $this->assertSame('Direct Course', $result->course->title);
        $this->assertSame('Direct description.', $result->course->description);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $result->course->source_system);
        $this->assertSame(
            [$second->id, $first->id],
            $result->course->courseEpisodes()->orderBy('sort_order')->pluck('episode_id')->all(),
        );
    }

    public function test_missing_or_cross_owner_episode_returns_not_found_without_writes(): void
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $owned = $this->episodeFor($user, $sourceUserId, ContentSourceSystem::LEARNING_OS);
        $other = $this->episodeFor($user, (string) Str::uuid(), ContentSourceSystem::LEARNING_OS);

        foreach ([$other->id, (string) Str::uuid()] as $unavailableId) {
            $result = app(CreateContentCourseAction::class)->handle(CreateContentCourseData::fromInput(
                $user->id,
                $sourceUserId,
                [
                    ...$this->baseInput(),
                    'description' => 'No provider call.',
                    'episodeIds' => [$owned->id, $unavailableId],
                ],
            ));

            $this->assertFalse($result->episodesFound);
            $this->assertNull($result->course);
        }

        $this->assertDatabaseCount('content_courses', 0);
        $this->assertDatabaseCount('content_episode_courses', 0);
    }

    public function test_direct_input_normalizes_ids_before_rejecting_duplicates(): void
    {
        $episodeId = (string) Str::uuid();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course Episode IDs must be unique.');

        CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [$episodeId, '  '.strtoupper($episodeId).'  '],
        ]);
    }

    public function test_direct_input_rejects_malformed_episode_ids_before_the_action_runs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Episode ID must be a UUID.');

        CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => ['not-a-uuid'],
        ]);
    }

    public function test_direct_input_accepts_duration_boundaries_and_defaults(): void
    {
        $episodeId = (string) Str::uuid();

        $minimum = CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [$episodeId],
            'maxLessonDurationMinutes' => 1,
        ]);
        $maximum = CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [$episodeId],
            'maxLessonDurationMinutes' => 120,
        ]);
        $default = CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [$episodeId],
        ]);
        $signedString = CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [$episodeId],
            'maxLessonDurationMinutes' => '+3',
        ]);

        $this->assertSame(1, $minimum->maxLessonDurationMinutes);
        $this->assertSame(120, $maximum->maxLessonDurationMinutes);
        $this->assertSame(30, $default->maxLessonDurationMinutes);
        $this->assertSame(3, $signedString->maxLessonDurationMinutes);
    }

    #[DataProvider('invalidDurations')]
    public function test_direct_input_rejects_duration_outside_the_persisted_domain(int $duration): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course duration must be between 1 and 120 minutes.');

        CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [(string) Str::uuid()],
            'maxLessonDurationMinutes' => $duration,
        ]);
    }

    /** @return array<string, array{int}> */
    public static function invalidDurations(): array
    {
        return [
            'below minimum' => [0],
            'above maximum' => [121],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function malformedDurations(): array
    {
        return [
            'decimal string' => ['1.5'],
            'non-numeric string' => ['abc'],
            'array' => [[30]],
        ];
    }

    #[DataProvider('malformedDurations')]
    public function test_direct_input_rejects_malformed_duration_values(mixed $duration): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course duration must be an integer.');

        CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'episodeIds' => [(string) Str::uuid()],
            'maxLessonDurationMinutes' => $duration,
        ]);
    }

    public function test_direct_input_rejects_wrong_optional_string_shapes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course description must be a string or null.');

        CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'description' => ['not a string'],
            'episodeIds' => [(string) Str::uuid()],
        ]);
    }

    public function test_direct_input_enforces_episode_count_and_voice_length_limits(): void
    {
        $base = [
            ...$this->baseInput(),
            'title' => str_repeat('c', 255),
            'episodeIds' => array_map(
                static fn (): string => (string) Str::uuid(),
                range(1, 100),
            ),
            'l1VoiceId' => str_repeat('v', 255),
            'speaker1VoiceId' => str_repeat('s', 255),
            'speaker2VoiceId' => str_repeat('t', 255),
        ];

        $accepted = CreateContentCourseData::fromInput(1, (string) Str::uuid(), $base);
        $this->assertCount(100, $accepted->episodeIds);
        $this->assertSame(str_repeat('c', 255), $accepted->title);
        $this->assertSame(str_repeat('v', 255), $accepted->l1VoiceId);

        foreach ([
            'episodeIds' => [...$base['episodeIds'], (string) Str::uuid()],
            'title' => str_repeat('c', 256),
            'l1VoiceId' => str_repeat('v', 256),
            'speaker1VoiceId' => str_repeat('s', 256),
            'speaker2VoiceId' => str_repeat('t', 256),
        ] as $field => $invalidValue) {
            try {
                CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
                    ...$base,
                    $field => $invalidValue,
                ]);
                $this->fail("Expected {$field} to reject its upper boundary.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_inline_source_text_takes_precedence_at_the_direct_boundary(): void
    {
        $data = CreateContentCourseData::fromInput(1, (string) Str::uuid(), [
            ...$this->baseInput(),
            'sourceText' => '  Inline text.  ',
            'episodeIds' => ['ignored-malformed-id'],
        ]);

        $this->assertSame('Inline text.', $data->sourceText);
        $this->assertSame([], $data->episodeIds);
    }

    /** @return array<string, string> */
    private function baseInput(): array
    {
        return [
            'title' => 'Course',
            'nativeLanguage' => 'en',
            'targetLanguage' => 'ja',
        ];
    }

    private function episodeFor(
        User $user,
        string $sourceUserId,
        string $sourceSystem,
    ): ContentEpisode {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $sourceUserId,
            'source_system' => $sourceSystem,
            'title' => 'Episode',
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'jlpt_level' => null,
            'auto_generate_audio' => true,
            'status' => 'draft',
            'is_sample_content' => false,
            'audio_speed' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
