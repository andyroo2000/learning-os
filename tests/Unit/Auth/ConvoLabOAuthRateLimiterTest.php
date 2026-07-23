<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

class ConvoLabOAuthRateLimiterTest extends TestCase
{
    public function test_resolve_normalizes_email_and_has_a_separate_network_ceiling(): void
    {
        [$identity, $network] = ConvoLabOAuthRateLimiter::resolve($this->request(
            ['email' => ' ADA@Example.com '],
            '192.0.2.10',
        ));

        $this->assertSame(
            'convolab-oauth-resolve|email:ada@example.com|ip:192.0.2.10',
            $identity->key,
        );
        $this->assertSame('convolab-oauth-resolve-network|ip:192.0.2.10', $network->key);
        $this->assertSame(12, $identity->maxAttempts);
        $this->assertSame(60, $network->maxAttempts);
        $this->assertNotSame($identity->key, $network->key);
    }

    public function test_resolve_uses_stable_fallbacks_for_invalid_identity_and_network_inputs(): void
    {
        [$identity, $network] = ConvoLabOAuthRateLimiter::resolve($this->request(
            ['email' => ['not-a-scalar']],
            null,
        ));

        $this->assertSame(
            'convolab-oauth-resolve|missing-email|missing-ip',
            $identity->key,
        );
        $this->assertSame('convolab-oauth-resolve-network|missing-ip', $network->key);
    }

    public function test_claim_and_disconnect_have_distinct_user_and_network_buckets(): void
    {
        $request = $this->request([], '198.51.100.7');
        $request->headers->set('X-Convo-Lab-User-Id', ' ABC-123 ');

        [$claimIdentity, $claimNetwork] = ConvoLabOAuthRateLimiter::authenticated(
            ConvoLabOAuthRateLimiter::CLAIM,
            $request,
        );
        [$disconnectIdentity, $disconnectNetwork] = ConvoLabOAuthRateLimiter::authenticated(
            ConvoLabOAuthRateLimiter::DISCONNECT,
            $request,
        );

        $this->assertSame(
            'convolab-oauth-claim|user:'.hash('sha256', 'abc-123'),
            $claimIdentity->key,
        );
        $this->assertSame(
            'convolab-oauth-disconnect|user:'.hash('sha256', 'abc-123'),
            $disconnectIdentity->key,
        );
        $this->assertSame('convolab-oauth-claim-network|ip:198.51.100.7', $claimNetwork->key);
        $this->assertSame(
            'convolab-oauth-disconnect-network|ip:198.51.100.7',
            $disconnectNetwork->key,
        );
        $this->assertSame(5, $claimIdentity->maxAttempts);
        $this->assertSame(5, $disconnectIdentity->maxAttempts);
        $this->assertNotSame($claimIdentity->key, $disconnectIdentity->key);
        $this->assertNotSame($claimNetwork->key, $disconnectNetwork->key);
    }

    public function test_authenticated_identity_buckets_are_user_scoped_with_an_ip_fallback(): void
    {
        $first = $this->request([], '203.0.113.9');
        $first->headers->set('X-Convo-Lab-User-Id', 'user-one');
        $second = $this->request([], '203.0.113.9');
        $second->headers->set('X-Convo-Lab-User-Id', 'user-two');
        $anonymous = $this->request([], '203.0.113.9');

        [$firstIdentity] = ConvoLabOAuthRateLimiter::authenticated(ConvoLabOAuthRateLimiter::CLAIM, $first);
        [$secondIdentity] = ConvoLabOAuthRateLimiter::authenticated(ConvoLabOAuthRateLimiter::CLAIM, $second);
        [$anonymousIdentity] = ConvoLabOAuthRateLimiter::authenticated(ConvoLabOAuthRateLimiter::CLAIM, $anonymous);

        $this->assertSame(
            'convolab-oauth-claim|user:'.hash('sha256', 'user-one'),
            $firstIdentity->key,
        );
        $this->assertSame(
            'convolab-oauth-claim|user:'.hash('sha256', 'user-two'),
            $secondIdentity->key,
        );
        $this->assertSame('convolab-oauth-claim|anon:ip:203.0.113.9', $anonymousIdentity->key);
        $this->assertNotSame($firstIdentity->key, $secondIdentity->key);
        $this->assertStringNotContainsString('user-one', $firstIdentity->key);
    }

    public function test_browser_limits_hash_session_identity_and_keep_start_and_callback_separate(): void
    {
        $request = $this->request([], '192.0.2.40');
        $session = new Store(
            'oauth-rate-limit-test',
            new ArraySessionHandler(120),
            'browser-session-id',
        );
        $session->start();
        $request->setLaravelSession($session);

        [$start, $startNetwork] = ConvoLabOAuthRateLimiter::browser(
            ConvoLabOAuthRateLimiter::BROWSER_START,
            $request,
        );
        [$callback, $callbackNetwork] = ConvoLabOAuthRateLimiter::browser(
            ConvoLabOAuthRateLimiter::BROWSER_CALLBACK,
            $request,
        );
        [$claim, $claimNetwork] = ConvoLabOAuthRateLimiter::browserClaim($request);

        $this->assertNotSame($start->key, $callback->key);
        $this->assertNotSame($start->key, $claim->key);
        $this->assertNotSame($callback->key, $claim->key);
        $this->assertStringContainsString(
            hash('sha256', $request->session()->getId()),
            $start->key,
        );
        $this->assertStringContainsString(
            hash('sha256', $request->session()->getId()),
            $claim->key,
        );
        $this->assertSame(20, $start->maxAttempts);
        $this->assertSame(20, $callback->maxAttempts);
        $this->assertSame(5, $claim->maxAttempts);
        $this->assertSame(60, $startNetwork->maxAttempts);
        $this->assertSame(60, $callbackNetwork->maxAttempts);
        $this->assertSame(60, $claimNetwork->maxAttempts);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload, ?string $ip): Request
    {
        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $request = Request::create('/api/convolab/auth/google', 'POST', $payload, [], [], $server);
        if ($ip === null) {
            $request->server->remove('REMOTE_ADDR');
        }

        return $request;
    }
}
