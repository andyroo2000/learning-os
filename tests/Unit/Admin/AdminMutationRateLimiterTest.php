<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AdminMutationRateLimiterTest extends TestCase
{
    public function test_pronunciation_update_uses_the_admin_default_and_operation_scoped_identity_key(): void
    {
        $actor = '01a1f0db-a4c8-4caa-adf6-d3801fdd7061';
        $request = Request::create('/api/convolab/admin/pronunciation-dictionaries', 'PUT');
        $request->headers->set('X-Convo-Lab-User-Id', $actor);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $limit = AdminMutationRateLimiter::limit(
            AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE,
            $request,
        );

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertStringStartsWith(
            AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE.':',
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
}
