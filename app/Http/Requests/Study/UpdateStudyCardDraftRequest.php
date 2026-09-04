<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Http\Requests\Study\Concerns\ValidatesStudyCardPayloads;
use App\Http\Requests\Study\Concerns\ValidatesVocabVariantMetadata;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use BackedEnum;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateStudyCardDraftRequest extends FormRequest
{
    use ValidatesStudyCardPayloads;
    use ValidatesVocabVariantMetadata;

    private const PAYLOAD_REQUIRED_MESSAGE = 'prompt and answer payloads are required.';

    private ?StudyCardDraft $studyCardDraft = null;

    protected function prepareForValidation(): void
    {
        $normalized = [
            ...$this->normalizedChoiceInputs(),
            ...$this->normalizedImagePromptInput(),
        ];

        $this->mergeNormalizedVocabVariantMetadataForValidation($normalized);

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, string> */
    private function normalizedChoiceInputs(): array
    {
        $normalized = [];

        foreach (['imagePlacement', 'previewAudioRole'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = strtolower(trim($value));
            }
        }

        return $normalized;
    }

    /** @return array{imagePrompt: ?string}|array{} */
    private function normalizedImagePromptInput(): array
    {
        if (! array_key_exists('imagePrompt', $this->all())) {
            return [];
        }

        $value = $this->input('imagePrompt');
        if (! is_string($value)) {
            return [];
        }

        $trimmed = trim($value);

        return ['imagePrompt' => $trimmed === '' ? null : $trimmed];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $draftId = (string) $this->route('draftId');

        if ($user === null) {
            throw new AuthenticationException;
        }

        $this->studyCardDraft = StudyCardDraft::query()
            ->where('user_id', AuthenticatedUser::id($this))
            ->whereKey(CanonicalUlid::normalize($draftId))
            ->first();

        if ($this->studyCardDraft === null) {
            throw new NotFoundHttpException('Study card draft not found.');
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'expectedRevision' => ['sometimes', 'integer', 'min:0'],
            'prompt' => ['sometimes', 'array'],
            'answer' => ['sometimes', 'array'],
            'imagePlacement' => ['sometimes', 'nullable', 'string', Rule::in(StudyCardImagePlacement::values())],
            'imagePrompt' => ['sometimes', 'nullable', 'string', 'max:'.StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH],
            'previewAudio' => ['sometimes', 'nullable', 'array:'.implode(',', StudyCardDraft::MEDIA_REF_ALLOWED_KEYS)],
            'previewAudio.id' => ['sometimes', 'nullable', 'string'],
            'previewAudio.filename' => ['required_with:previewAudio', 'string', 'filled'],
            'previewAudio.url' => ['sometimes', 'nullable', 'string'],
            'previewAudio.mediaKind' => ['required_with:previewAudio', 'string', Rule::in(['audio'])],
            'previewAudio.source' => ['required_with:previewAudio', 'string', Rule::in(StudyCardDraft::MEDIA_SOURCES)],
            'previewAudioRole' => ['sometimes', 'nullable', 'string', Rule::in(StudyCardAudioRole::values())],
            'previewImage' => ['sometimes', 'nullable', 'array:'.implode(',', StudyCardDraft::MEDIA_REF_ALLOWED_KEYS)],
            'previewImage.id' => ['sometimes', 'nullable', 'string'],
            'previewImage.filename' => ['required_with:previewImage', 'string', 'filled'],
            'previewImage.url' => ['sometimes', 'nullable', 'string'],
            'previewImage.mediaKind' => ['required_with:previewImage', 'string', Rule::in(['image'])],
            'previewImage.source' => ['required_with:previewImage', 'string', Rule::in(StudyCardDraft::MEDIA_SOURCES)],
            ...$this->variantMetadataRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->studyCardPayloadAfterValidator(requireText: false),
            $this->validateStudyCardDraftUpdate(...),
        ];
    }

    private function validateStudyCardDraftUpdate(Validator $validator): void
    {
        $data = $validator->getData();
        $this->addMissingPayloadPairErrors($validator, $data);
        $this->addMissingPreviewAudioError($validator, $data);
    }

    /** @param array<string, mixed> $data */
    private function addMissingPayloadPairErrors(Validator $validator, array $data): void
    {
        if (array_key_exists('prompt', $data) xor array_key_exists('answer', $data)) {
            $validator->errors()->add('prompt', self::PAYLOAD_REQUIRED_MESSAGE);
            $validator->errors()->add('answer', self::PAYLOAD_REQUIRED_MESSAGE);
        }
    }

    /** @param array<string, mixed> $data */
    private function addMissingPreviewAudioError(Validator $validator, array $data): void
    {
        if (! self::hasValidPreviewAudioRole($validator, $data)) {
            return;
        }

        if (self::addsValidPreviewAudio($validator, $data)) {
            return;
        }

        $clearsPreviewAudio = array_key_exists('previewAudio', $data) && $data['previewAudio'] === null;
        if (! $clearsPreviewAudio && $this->studyCardDraft()->preview_audio_json !== null) {
            return;
        }

        $validator->errors()->add('previewAudioRole', 'previewAudioRole requires previewAudio.');
    }

    /** @param array<string, mixed> $data */
    private static function hasValidPreviewAudioRole(Validator $validator, array $data): bool
    {
        if (! array_key_exists('previewAudioRole', $data)) {
            return false;
        }

        if ($data['previewAudioRole'] === null) {
            return false;
        }

        return ! $validator->errors()->has('previewAudioRole');
    }

    /** @param array<string, mixed> $data */
    private static function addsValidPreviewAudio(Validator $validator, array $data): bool
    {
        if (! array_key_exists('previewAudio', $data) || $data['previewAudio'] === null) {
            return false;
        }

        return ! self::previewAudioHasErrors($validator);
    }

    private static function previewAudioHasErrors(Validator $validator): bool
    {
        foreach ($validator->errors()->keys() as $errorKey) {
            if ($errorKey === 'previewAudio' || str_starts_with($errorKey, 'previewAudio.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->studyCardPayloadMessages(),
            'expectedRevision.integer' => 'expectedRevision must be a non-negative integer.',
            'expectedRevision.min' => 'expectedRevision must be a non-negative integer.',
            'prompt.array' => self::PAYLOAD_REQUIRED_MESSAGE,
            'answer.array' => self::PAYLOAD_REQUIRED_MESSAGE,
            'imagePlacement.in' => self::studyCardImagePlacementMessage(),
            'imagePrompt.max' => 'imagePrompt must be '.StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH.' characters or fewer.',
            'previewAudio.array' => 'draft.previewAudio must be a media reference object or null.',
            'previewAudio.filename.required_with' => 'draft.previewAudio.filename is required.',
            'previewAudio.filename.filled' => 'draft.previewAudio.filename is required.',
            'previewAudio.mediaKind.required_with' => 'draft.previewAudio.mediaKind must be audio.',
            'previewAudio.mediaKind.in' => 'draft.previewAudio.mediaKind must be audio.',
            'previewAudio.source.required_with' => self::studyCardMediaSourcesMessage(),
            'previewAudio.source.in' => self::studyCardMediaSourcesMessage(),
            'previewAudioRole.in' => 'previewAudioRole must be prompt or answer.',
            'previewImage.array' => 'draft.previewImage must be a media reference object or null.',
            'previewImage.filename.required_with' => 'draft.previewImage.filename is required.',
            'previewImage.filename.filled' => 'draft.previewImage.filename is required.',
            'previewImage.mediaKind.required_with' => 'draft.previewImage.mediaKind must be image.',
            'previewImage.mediaKind.in' => 'draft.previewImage.mediaKind must be image.',
            'previewImage.source.required_with' => self::studyCardMediaSourcesMessage(),
            'previewImage.source.in' => self::studyCardMediaSourcesMessage(),
            ...$this->variantMetadataMessages(),
        ];
    }

    public function studyCardDraft(): StudyCardDraft
    {
        if ($this->studyCardDraft === null) {
            throw new LogicException('studyCardDraft() called before authorize() resolved the draft.');
        }

        return $this->studyCardDraft;
    }

    public function expectedRevision(): ?int
    {
        $validated = $this->validated();

        if (! array_key_exists('expectedRevision', $validated)) {
            return null;
        }

        $value = $validated['expectedRevision'];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer !== false) {
                return $integer;
            }
        }

        throw new LogicException('expectedRevision called after validation failed to reject a non-integer value.');
    }

    public function hasPrompt(): bool
    {
        return array_key_exists('prompt', $this->validated());
    }

    public function hasAnswer(): bool
    {
        return array_key_exists('answer', $this->validated());
    }

    public function hasImagePlacement(): bool
    {
        return array_key_exists('imagePlacement', $this->validated());
    }

    public function imagePlacement(): ?StudyCardImagePlacement
    {
        /** @var StudyCardImagePlacement|null */
        return $this->nullableValidatedEnum('imagePlacement', StudyCardImagePlacement::class);
    }

    public function hasImagePrompt(): bool
    {
        return array_key_exists('imagePrompt', $this->validated());
    }

    public function imagePrompt(): ?string
    {
        return $this->nullableValidatedStudyStringValue('imagePrompt');
    }

    public function hasPreviewAudio(): bool
    {
        return array_key_exists('previewAudio', $this->validated());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewAudio(): ?array
    {
        $value = $this->validated('previewAudio');

        if ($value !== null && ! is_array($value)) {
            throw new LogicException('previewAudio called after validation failed to reject a non-array value.');
        }

        return $value;
    }

    public function hasPreviewAudioRole(): bool
    {
        return array_key_exists('previewAudioRole', $this->validated());
    }

    public function previewAudioRole(): ?StudyCardAudioRole
    {
        /** @var StudyCardAudioRole|null */
        return $this->nullableValidatedEnum('previewAudioRole', StudyCardAudioRole::class);
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enumClass
     * @return TEnum|null
     */
    private function nullableValidatedEnum(string $key, string $enumClass): ?BackedEnum
    {
        $value = $this->validated($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException("{$key} called after validation failed to reject a non-string value.");
        }

        return $enumClass::from($value);
    }

    public function hasPreviewImage(): bool
    {
        return array_key_exists('previewImage', $this->validated());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewImage(): ?array
    {
        $value = $this->validated('previewImage');

        if ($value !== null && ! is_array($value)) {
            throw new LogicException('previewImage called after validation failed to reject a non-array value.');
        }

        return $value;
    }
}
