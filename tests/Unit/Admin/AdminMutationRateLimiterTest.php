<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laravel\Sanctum\TransientToken;
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
        $request->setUserResolver(fn (): User => (new User)->forceFill([
            'convolab_id' => $actor,
        ]));
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
        $first->setUserResolver(fn (): User => (new User)->forceFill([
            'convolab_id' => 'first-actor',
        ]));
        $second = Request::create('/', 'PUT', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $second->setUserResolver(fn (): User => (new User)->forceFill([
            'convolab_id' => 'second-actor',
        ]));

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

    public function test_browser_session_uses_its_authenticated_actor_instead_of_a_proxy_header(): void
    {
        $sessionActor = '01a1f0db-a4c8-4caa-adf6-d3801fdd7061';
        $spoofedActor = '9a362eff-925c-4e26-88b6-f6fb1bf73aa5';
        $user = (new User)
            ->forceFill(['convolab_id' => $sessionActor])
            ->withAccessToken(new TransientToken);
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->attributes->set('sanctum', true);
        $request->setLaravelSession(new Store('admin-rate-limit', new ArraySessionHandler(120)));
        $request->setUserResolver(fn (): User => $user);
        $request->headers->set('X-Convo-Lab-User-Id', $spoofedActor);

        $limit = AdminMutationRateLimiter::limit(
            AdminMutationRateLimiter::INVITE_CREATE,
            $request,
        );

        $this->assertStringContainsString(hash('sha256', $sessionActor), $limit->key);
        $this->assertStringNotContainsString(hash('sha256', $spoofedActor), $limit->key);
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
        yield 'Script Lab line synthesis' => [AdminMutationRateLimiter::SCRIPT_LAB_LINE_SYNTHESIZE, 6];
        yield 'Script Lab pronunciation test' => [AdminMutationRateLimiter::SCRIPT_LAB_PRONUNCIATION_TEST, 6];
        yield 'course pipeline update' => [AdminMutationRateLimiter::COURSE_PIPELINE_UPDATE, 30];
        yield 'course dialogue generate' => [AdminMutationRateLimiter::COURSE_DIALOGUE_GENERATE, 30];
        yield 'course script generate' => [AdminMutationRateLimiter::COURSE_SCRIPT_GENERATE, 30];
        yield 'course audio generate' => [AdminMutationRateLimiter::COURSE_AUDIO_GENERATE, 30];
        yield 'course line synthesis' => [AdminMutationRateLimiter::COURSE_LINE_SYNTHESIZE, 6];
        yield 'course line delete' => [AdminMutationRateLimiter::COURSE_LINE_DELETE, 30];
    }
}
