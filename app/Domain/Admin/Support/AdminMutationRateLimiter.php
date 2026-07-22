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

    public const COURSE_PIPELINE_UPDATE = 'convolab-admin-course-pipeline-update';

    public const COURSE_DIALOGUE_GENERATE = 'convolab-admin-course-dialogue-generate';

    public const COURSE_SCRIPT_GENERATE = 'convolab-admin-course-script-generate';

    public static function limit(string $operation, Request $request): Limit
    {
        return Limit::perMinute(30)->by(ConvoLabProfileRateLimiter::key(
            $operation,
            $request->header('X-Convo-Lab-User-Id'),
            $request->ip(),
        ));
    }
}
