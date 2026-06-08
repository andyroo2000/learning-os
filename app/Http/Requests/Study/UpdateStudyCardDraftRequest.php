<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Http\Requests\Study\Concerns\ValidatesStudyCardPayloads;
use App\Http\Requests\Study\Concerns\ValidatesVocabVariantMetadata;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
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
        $normalized = [];

        foreach (['imagePlacement', 'previewAudioRole'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = strtolower(trim($value));
            }
        }

        if (array_key_exists('imagePrompt', $this->all())) {
            $value = $this->input('imagePrompt');

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized['imagePrompt'] = $trimmed === '' ? null : $trimmed;
            }
        }

        $this->normalizeVariantMetadataForValidation($normalized);

        if ($normalized !== []) {
            $this->merge($normalized);
        }
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
            function (Validator $validator): void {
                $data = $validator->getData();

                if (array_key_exists('prompt', $data) xor array_key_exists('answer', $data)) {
                    $validator->errors()->add('prompt', self::PAYLOAD_REQUIRED_MESSAGE);
                    $validator->errors()->add('answer', self::PAYLOAD_REQUIRED_MESSAGE);
                }

                foreach (['prompt', 'answer'] as $attribute) {
                    $payload = $data[$attribute] ?? null;

                    if (is_array($payload) && $payload !== [] && array_is_list($payload)) {
                        $validator->errors()->add($attribute, self::PAYLOAD_REQUIRED_MESSAGE);
                    }
                }

                $hasPreviewAudioRole = array_key_exists('previewAudioRole', $data)
                    && $data['previewAudioRole'] !== null
                    && ! $validator->errors()->has('previewAudioRole');
                $previewAudioHasErrors = false;

                foreach ($validator->errors()->keys() as $errorKey) {
                    if ($errorKey === 'previewAudio' || str_starts_with($errorKey, 'previewAudio.')) {
                        $previewAudioHasErrors = true;

                        break;
                    }
                }

                $addsPreviewAudio = array_key_exists('previewAudio', $data)
                    && $data['previewAudio'] !== null
                    && ! $previewAudioHasErrors;
                $clearsPreviewAudio = array_key_exists('previewAudio', $data) && $data['previewAudio'] === null;

                if ($hasPreviewAudioRole
                    && ! $addsPreviewAudio
                    && ($clearsPreviewAudio || $this->studyCardDraft()->preview_audio_json === null)) {
                    $validator->errors()->add('previewAudioRole', 'previewAudioRole requires previewAudio.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->studyCardPayloadMessages(),
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
        $validated = $this->validated();
        $value = $validated['imagePlacement'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('imagePlacement called after validation failed to reject a non-string value.');
        }

        return StudyCardImagePlacement::from($value);
    }

    public function hasImagePrompt(): bool
    {
        return array_key_exists('imagePrompt', $this->validated());
    }

    public function imagePrompt(): ?string
    {
        return $this->nullableValidatedStringValue('imagePrompt');
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
        $value = $this->validated('previewAudioRole');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('previewAudioRole called after validation failed to reject a non-string value.');
        }

        return StudyCardAudioRole::from($value);
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
