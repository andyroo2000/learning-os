<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminMutationRateLimiterTest extends TestCase
{
    #[DataProvider('operationProvider')]
    public function test_mutations_use_operation_quotas_and_scoped_identity_keys(
        string $operation,
        int $attempts,
    ): void {
        $actor = '01a1f0db-a4c8-4caa-adf6-d3801fdd7061';
        $request = Request::create('/api/convolab/admin/pronunciation-dictionaries', 'PUT');
        $request->headers->set('X-Convo-Lab-User-Id', $actor);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $limit = AdminMutationRateLimiter::limit(
            $operation,
            $request,
        );

        $this->assertSame($attempts, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertStringStartsWith(
            $operation.':',
            $limit->key,
        );
        $this->assertStringContainsString(hash('sha256', $actor), $limit->key);
        $this->assertStringEndsWith(':127.0.0.1', $limit->key);
    }

    public function test_pronunciation_update_separates_actor_buckets(): void
    {
        $first = Request::create('/', 'PUT', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $first->headers->set('X-Convo-Lab-User-Id', 'first-actor');
        $second = Request::create('/', 'PUT', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $second->headers->set('X-Convo-Lab-User-Id', 'second-actor');

        $firstLimit = AdminMutationRateLimiter::limit(
            AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE,
            $first,
        );
        $secondLimit = AdminMutationRateLimiter::limit(
            AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE,
            $second,
        );

        $this->assertNotSame($firstLimit->key, $secondLimit->key);
    }

    public function test_missing_actor_and_ip_have_stable_fallback_keys(): void
    {
        $request = Request::create('/', 'POST');
        $request->server->remove('REMOTE_ADDR');
        $limit = AdminMutationRateLimiter::limit(
            AdminMutationRateLimiter::SPEAKER_AVATAR_UPLOAD,
            $request,
        );

        $this->assertSame(
            AdminMutationRateLimiter::SPEAKER_AVATAR_UPLOAD.':missing:unknown-ip',
            $limit->key,
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function operationProvider(): iterable
    {
        yield 'pronunciation dictionary' => [AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE, 30];
        yield 'speaker upload' => [AdminMutationRateLimiter::SPEAKER_AVATAR_UPLOAD, 30];
        yield 'speaker recrop' => [AdminMutationRateLimiter::SPEAKER_AVATAR_RECROP, 30];
        yield 'user upload' => [AdminMutationRateLimiter::USER_AVATAR_UPLOAD, 30];
        yield 'Script Lab course create' => [AdminMutationRateLimiter::SCRIPT_LAB_COURSE_CREATE, 30];
        yield 'Script Lab course delete' => [AdminMutationRateLimiter::SCRIPT_LAB_COURSE_DELETE, 30];
        yield 'sentence script generate' => [AdminMutationRateLimiter::SENTENCE_SCRIPT_GENERATE, 6];
        yield 'sentence script delete' => [AdminMutationRateLimiter::SENTENCE_SCRIPT_DELETE, 30];
        yield 'course pipeline update' => [AdminMutationRateLimiter::COURSE_PIPELINE_UPDATE, 30];
        yield 'course dialogue generate' => [AdminMutationRateLimiter::COURSE_DIALOGUE_GENERATE, 30];
        yield 'course script generate' => [AdminMutationRateLimiter::COURSE_SCRIPT_GENERATE, 30];
        yield 'course audio generate' => [AdminMutationRateLimiter::COURSE_AUDIO_GENERATE, 30];
        yield 'course line synthesis' => [AdminMutationRateLimiter::COURSE_LINE_SYNTHESIZE, 6];
        yield 'course line delete' => [AdminMutationRateLimiter::COURSE_LINE_DELETE, 30];
    }
}
