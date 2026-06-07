<?php

namespace App\Domain\Study\Models;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Models\User;
use Database\Factories\StudyCardDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Draft ownership is server-assigned; clients may edit content but not move drafts between users.
#[Fillable([
    'creation_kind',
    'prompt_json',
    'answer_json',
    'image_placement',
    'image_prompt',
    'preview_audio_json',
    'preview_audio_role',
    'preview_image_json',
])]
class StudyCardDraft extends Model
{
    /** @use HasFactory<StudyCardDraftFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings;

    public const MAX_IMAGE_PROMPT_LENGTH = 1000;

    public const MAX_VARIANT_ID_LENGTH = 64;

    public const MAX_VARIANT_STAGE = 65535;

    public const MAX_PAYLOAD_BYTES = 24 * 1024;

    // Maximum nested levels including the prompt/answer payload root itself.
    // Depth 1 is the root payload array; arrays at depth 9+ are rejected.
    public const MAX_TOTAL_PAYLOAD_DEPTH = 8;

    public const MEDIA_REF_ALLOWED_KEYS = ['id', 'filename', 'url', 'mediaKind', 'source'];

    public const MEDIA_SOURCES = ['imported', 'generated', 'missing', 'imported_image', 'imported_other'];

    private const GENERATION_OUTPUT_ATTRIBUTES = [
        'preview_audio_json',
        'preview_audio_role',
        'preview_image_json',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::saving(function (StudyCardDraft $draft): void {
            $draft->deriveCardTypeFromCreationKind();
        });

        static::updating(function (StudyCardDraft $draft): void {
            if ($draft->isDirty('user_id')) {
                throw new LogicException('Study card draft owner cannot be changed.');
            }
        });
    }

    protected static function newFactory(): StudyCardDraftFactory
    {
        return StudyCardDraftFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StudyManualCardDraftStatus::class,
            'creation_kind' => StudyCardCreationKind::class,
            'card_type' => CardType::class,
            'prompt_json' => 'array',
            'answer_json' => 'array',
            'image_placement' => StudyCardImagePlacement::class,
            'preview_audio_json' => 'array',
            'preview_audio_role' => StudyCardAudioRole::class,
            'preview_image_json' => 'array',
            'variant_kind' => StudyVocabVariantKind::class,
            'variant_stage' => 'integer',
            'variant_status' => StudyVocabVariantStatus::class,
            'variant_unlocked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resetForRetry(): void
    {
        // This storage slice matches draft creation: the generation worker/pickup path owns
        // fulfillment later, while retry owns the durable pending state and stale output reset.
        $this->status = StudyManualCardDraftStatus::Generating;
        $this->resetGenerationOutput();
    }

    private function resetGenerationOutput(): void
    {
        foreach (self::GENERATION_OUTPUT_ATTRIBUTES as $attribute) {
            $this->{$attribute} = null;
        }
    }

    private function deriveCardTypeFromCreationKind(): void
    {
        if ($this->exists && ! $this->isDirty('creation_kind')) {
            return;
        }

        if (! $this->creation_kind instanceof StudyCardCreationKind) {
            throw new LogicException('Study card draft creation kind must be set before saving.');
        }

        $this->card_type = $this->creation_kind->cardType();
    }
}
