<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardDraftPreviewMediaRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class StudyCardDraftPreviewMediaRateLimiterTest extends TestCase
{
    public function test_it_uses_a_named_user_bucket_with_the_default_limit(): void
    {
        $request = Request::create('/api/study/card-drafts/draft/preview-audio', 'POST');
        $user = new User;
        $user->id = 42;
        $request->setUserResolver(fn (): User => $user);

        $limit = (new StudyCardDraftPreviewMediaRateLimiter)->limit($request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame('study-card-draft-preview-media:user:42', $limit->key);
    }

    public function test_it_falls_back_to_a_named_network_bucket(): void
    {
        $this->assertSame(
            'study-card-draft-preview-media:anon:203.0.113.10',
            (new StudyCardDraftPreviewMediaRateLimiter)->keyFor(null, '203.0.113.10'),
        );
    }
}
