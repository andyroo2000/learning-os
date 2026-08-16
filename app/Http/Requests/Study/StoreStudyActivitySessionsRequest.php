<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Support\StudyActivitySessionId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStudyActivitySessionsRequest extends FormRequest
{
    public const MAX_DURATION_MS = StudyActivitySessionData::MAX_DURATION_MS;

    private const ISO_8601_TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sessions = $this->input('sessions');
        if (! is_array($sessions)) {
            return;
        }

        foreach ($sessions as $index => $session) {
            if (! is_array($session)) {
                continue;
            }

            foreach (['clientSessionId', 'category', 'activity', 'source', 'origin', 'name'] as $key) {
                if (isset($session[$key]) && is_string($session[$key])) {
                    $sessions[$index][$key] = trim($session[$key]);
                }
            }

            foreach (['category', 'activity', 'source', 'origin'] as $key) {
                if (isset($sessions[$index][$key]) && is_string($sessions[$index][$key])) {
                    $sessions[$index][$key] = strtolower($sessions[$index][$key]);
                }
            }

            // Clients released before the Listen category paired Daily Audio with
            // Review. Preserve their queued/offline sessions while storing the
            // canonical category used by current analytics.
            if (($sessions[$index]['activity'] ?? null) === StudyActivityKind::DailyAudio->value
                && ($sessions[$index]['category'] ?? null) === StudyActivityCategory::Review->value) {
                $sessions[$index]['category'] = StudyActivityCategory::Listen->value;
            }

            if (isset($sessions[$index]['clientSessionId'])
                && is_string($sessions[$index]['clientSessionId'])) {
                $sessions[$index]['clientSessionId'] = StudyActivitySessionId::normalize(
                    $sessions[$index]['clientSessionId'],
                );
            }
        }

        $this->merge(['sessions' => $sessions]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sessions' => ['required', 'array', 'min:1', 'max:100'],
            'sessions.*' => ['required', 'array'],
            'sessions.*.clientSessionId' => ['required', 'distinct', 'string', 'max:64', 'regex:/^(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F-]{36})$/'],
            'sessions.*.category' => ['required', Rule::enum(StudyActivityCategory::class)],
            'sessions.*.activity' => ['required', Rule::enum(StudyActivityKind::class)],
            'sessions.*.source' => ['required', Rule::enum(StudyActivitySource::class)],
            // Provider and system origins are reserved for trusted server-side
            // writers. Public sync clients may only identify their own platform.
            'sessions.*.origin' => ['sometimes', 'nullable', Rule::in(StudyActivityOrigin::clientValues())],
            'sessions.*.sourceKey' => ['prohibited'],
            'sessions.*.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sessions.*.startedAt' => ['required', 'string', 'regex:'.self::ISO_8601_TIMESTAMP, 'date'],
            'sessions.*.endedAt' => ['required', 'string', 'regex:'.self::ISO_8601_TIMESTAMP, 'date', 'after_or_equal:sessions.*.startedAt'],
            'sessions.*.durationMs' => ['required', 'integer', 'min:0', 'max:'.self::MAX_DURATION_MS],
            'sessions.*.audioPlaybackMs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_DURATION_MS],
            'sessions.*.cardsCreated' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->validated('sessions') as $index => $session) {
                    if (StudyActivityKind::from($session['activity'])->category()->value !== $session['category']) {
                        $validator->errors()->add(
                            "sessions.$index.category",
                            'The category does not match the selected activity.',
                        );
                    }
                }
            },
        ];
    }

    /** @return list<StudyActivitySessionData> */
    public function sessions(): array
    {
        return array_map(
            StudyActivitySessionData::fromValidated(...),
            $this->validated('sessions'),
        );
    }
}
