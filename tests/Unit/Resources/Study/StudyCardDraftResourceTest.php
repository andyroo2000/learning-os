<?php

namespace Tests\Unit\Resources\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Http\Resources\Study\StudyCardDraftResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

enum IntegerBackedStudyCardDraftResourceValue: int
{
    case Legacy = 1;
}

enum StringBackedStudyCardDraftResourceValue: string
{
    case Ready = 'ready';
}

class StudyCardDraftResourceTest extends TestCase
{
    public function test_resource_serializes_string_draft_attributes_from_raw_values(): void
    {
        $draft = new StudyCardDraft;
        $draft->setRawAttributes([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'status' => 'generating',
            'creation_kind' => 'production-image',
            'card_type' => 'production',
            'prompt_json' => json_encode(['cueText' => 'front'], JSON_THROW_ON_ERROR),
            'answer_json' => json_encode(['answerText' => 'dog'], JSON_THROW_ON_ERROR),
            'image_placement' => 'prompt',
            'image_prompt' => 'A friendly dog',
            'preview_audio_json' => json_encode(['id' => 'audio-1'], JSON_THROW_ON_ERROR),
            'preview_audio_role' => null,
            'preview_image_json' => json_encode(['id' => 'image-1'], JSON_THROW_ON_ERROR),
            'error_message' => null,
            'committed_card_id' => null,
        ], sync: true);

        $resource = StudyCardDraftResource::make($draft)->toArray(new Request);

        $this->assertSame('generating', $resource['status']);
        $this->assertSame('production-image', $resource['creationKind']);
        $this->assertSame('production', $resource['cardType']);
        $this->assertSame('prompt', $resource['imagePlacement']);
        $this->assertNull($resource['previewAudioRole']);
        $this->assertNull($resource['errorMessage']);
        $this->assertNull($resource['committedCardId']);
    }

    public function test_resource_serializes_string_backed_enum_draft_attributes(): void
    {
        $resourceSubject = new StudyCardDraftResourceSubject([
            'status' => StringBackedStudyCardDraftResourceValue::Ready,
        ]);

        $resource = StudyCardDraftResource::make($resourceSubject)->toArray(new Request);

        $this->assertSame('ready', $resource['status']);
    }

    public function test_resource_rejects_non_string_scalar_draft_attributes(): void
    {
        $resourceSubject = new StudyCardDraftResourceSubject([
            'status' => false,
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study card draft attribute [status] must serialize to a string or null.');

        StudyCardDraftResource::make($resourceSubject)->toArray(new Request);
    }

    public function test_resource_rejects_plain_integer_draft_attributes(): void
    {
        $resourceSubject = new StudyCardDraftResourceSubject([
            'status' => 1,
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study card draft attribute [status] must serialize to a string or null.');

        StudyCardDraftResource::make($resourceSubject)->toArray(new Request);
    }

    public function test_resource_rejects_float_draft_attributes(): void
    {
        $resourceSubject = new StudyCardDraftResourceSubject([
            'status' => 1.5,
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study card draft attribute [status] must serialize to a string or null.');

        StudyCardDraftResource::make($resourceSubject)->toArray(new Request);
    }

    public function test_resource_rejects_integer_backed_enum_draft_attributes(): void
    {
        $resourceSubject = new StudyCardDraftResourceSubject([
            'status' => IntegerBackedStudyCardDraftResourceValue::Legacy,
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Study card draft attribute [status] must serialize to a string or null. Integer-backed enums are not supported.'
        );

        StudyCardDraftResource::make($resourceSubject)->toArray(new Request);
    }
}

final class StudyCardDraftResourceSubject
{
    public string $id = '01jzq4nny5xbnzw14q1g68b2yt';

    /** @var array<string, mixed>|null */
    public ?array $prompt_json = null;

    /** @var array<string, mixed>|null */
    public ?array $answer_json = null;

    public ?string $image_prompt = null;

    /** @var array<string, mixed>|null */
    public ?array $preview_audio_json = null;

    /** @var array<string, mixed>|null */
    public ?array $preview_image_json = null;

    public ?string $error_message = null;

    public ?string $committed_card_id = null;

    public mixed $created_at = null;

    public mixed $updated_at = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(private readonly array $attributes) {}

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
