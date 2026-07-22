<?php

namespace App\Domain\Admin\Support;

use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class AdminMutationRateLimiter
{
    public const USER_DELETE = 'convolab-admin-user-delete';

    public const INVITE_CREATE = 'convolab-admin-invite-create';

    public const INVITE_DELETE = 'convolab-admin-invite-delete';

    public const PRONUNCIATION_DICTIONARY_UPDATE = 'convolab-admin-pronunciation-dictionary-update';

    public const SPEAKER_AVATAR_UPLOAD = 'convolab-admin-speaker-avatar-upload';

    public const SPEAKER_AVATAR_RECROP = 'convolab-admin-speaker-avatar-recrop';

    public const USER_AVATAR_UPLOAD = 'convolab-admin-user-avatar-upload';

    public const SCRIPT_LAB_COURSE_CREATE = 'convolab-admin-script-lab-course-create';

    public const SCRIPT_LAB_COURSE_DELETE = 'convolab-admin-script-lab-course-delete';

    public const SENTENCE_SCRIPT_GENERATE = 'convolab-admin-sentence-script-generate';

    public const SENTENCE_SCRIPT_DELETE = 'convolab-admin-sentence-script-delete';

    public const SCRIPT_LAB_LINE_SYNTHESIZE = 'convolab-admin-script-lab-line-synthesize';

    public const COURSE_PIPELINE_UPDATE = 'convolab-admin-course-pipeline-update';

    public const COURSE_DIALOGUE_GENERATE = 'convolab-admin-course-dialogue-generate';

    public const COURSE_SCRIPT_GENERATE = 'convolab-admin-course-script-generate';

    public const COURSE_AUDIO_GENERATE = 'convolab-admin-course-audio-generate';

    public const COURSE_LINE_SYNTHESIZE = 'convolab-admin-course-line-synthesize';

    public const COURSE_LINE_DELETE = 'convolab-admin-course-line-delete';

    public static function limit(string $operation, Request $request): Limit
    {
        $attempts = in_array($operation, [
            self::COURSE_LINE_SYNTHESIZE,
            self::SENTENCE_SCRIPT_GENERATE,
            self::SCRIPT_LAB_LINE_SYNTHESIZE,
        ], true) ? 6 : 30;

        return Limit::perMinute($attempts)->by(ConvoLabProfileRateLimiter::key(
            $operation,
            $request->header('X-Convo-Lab-User-Id'),
            $request->ip(),
        ));
    }
}
