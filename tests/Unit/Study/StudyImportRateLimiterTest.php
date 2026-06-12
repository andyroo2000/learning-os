<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyImportRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudyImportRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_operation_scoped_keys(): void
    {
        $this->assertSame('study-import-create:user:42', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, 42, '127.0.0.1'));
        $this->assertSame('study-import-create:user:42', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, 42, '192.0.2.10'));
        $this->assertSame('study-import-create:anon:unknown-ip', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, null, null));
        $this->assertSame('study-import-create:anon:unknown-ip', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, null, ''));
        $this->assertSame('study-import-create:anon:127.0.0.1', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, null, '127.0.0.1'));
        $this->assertSame('study-import-create:anon:127.0.0.1', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, '', '127.0.0.1'));
        $this->assertSame('study-import-create:user:0', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, 0, '127.0.0.1'));
        $this->assertSame('study-import-create:anon:127.0.0.1', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, false, '127.0.0.1'));
        $this->assertSame('study-import-create:user:user-1', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CREATE_NAME, 'user-1', ''));
        $this->assertSame('study-import-upload:user:42', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::UPLOAD_NAME, 42, '127.0.0.1'));
        $this->assertSame('study-import-upload:anon:127.0.0.1', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::UPLOAD_NAME, '', '127.0.0.1'));
        $this->assertSame('study-import-upload:anon:127.0.0.1', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::UPLOAD_NAME, false, '127.0.0.1'));
        $this->assertSame('study-import-upload:user:0', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::UPLOAD_NAME, 0, '127.0.0.1'));
        $this->assertSame('study-import-complete:user:42', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::COMPLETE_NAME, 42, '127.0.0.1'));
        $this->assertSame('study-import-cancel:user:42', StudyImportRateLimiter::keyFor(StudyImportRateLimiter::CANCEL_NAME, 42, '127.0.0.1'));
    }

    public function test_create_session_uses_10_attempts_per_minute_by_default(): void
    {
        $limit = StudyImportRateLimiter::forCreateSession()->limit($this->requestWithUserId(42));

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-import-create:user:42', $limit->key);
    }

    public function test_upload_uses_30_attempts_per_minute_by_default(): void
    {
        $limit = StudyImportRateLimiter::forUpload()->limit($this->requestWithUserId(42));

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-import-upload:user:42', $limit->key);
    }

    public function test_complete_uses_30_attempts_per_minute_by_default(): void
    {
        $limit = StudyImportRateLimiter::forComplete()->limit($this->requestWithUserId(42));

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-import-complete:user:42', $limit->key);
    }

    public function test_cancel_uses_30_attempts_per_minute_by_default(): void
    {
        $limit = StudyImportRateLimiter::forCancel()->limit($this->requestWithUserId(42));

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-import-cancel:user:42', $limit->key);
    }

    private function requestWithUserId(int $userId): Request
    {
        $request = Request::create('/api/study/imports', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class($userId)
        {
            public function __construct(private readonly int $userId) {}

            public function getAuthIdentifier(): int
            {
                return $this->userId;
            }
        });

        return $request;
    }
}
