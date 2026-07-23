<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Contracts\ToolAnalyticsLogger;
use App\Domain\Analytics\Support\ToolAnalyticsRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StoreToolAnalyticsEventApiTest extends TestCase
{
    use RefreshDatabase;

    private ToolAnalyticsLogger $logger;

    /**
     * @var list<array<string, mixed>>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');

        $this->logger = new class($this->events) implements ToolAnalyticsLogger
        {
            /**
             * @param  list<array<string, mixed>>  $events
             */
            public function __construct(private array &$events) {}

            public function write(array $event): void
            {
                $this->events[] = $event;
            }
        };

        $this->app->instance(ToolAnalyticsLogger::class, $this->logger);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/convolab/tools/analytics', $this->validPayload())
            ->assertUnauthorized();

        $this->assertSame([], $this->events);
    }

    public function test_store_route_uses_the_named_limiter(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/tools/analytics'
                && in_array('POST', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.ToolAnalyticsRateLimiter::NAME,
            $route->gatherMiddleware(),
        );
    }

    public function test_store_rejects_wildcard_non_proxy_and_wrong_account_tokens(): void
    {
        $wildcard = User::factory()->create(['email' => 'proxy@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        $nonProxy = User::factory()->create()
            ->createToken('mobile', ['tools:analytics'])
            ->plainTextToken;
        $wrongAccount = User::factory()->create(['email' => 'other@example.com'])
            ->createToken('convolab-proxy', ['tools:analytics'])
            ->plainTextToken;

        foreach ([$wildcard, $nonProxy, $wrongAccount] as $token) {
            $this->withToken($token)
                ->postJson('/api/convolab/tools/analytics', $this->validPayload())
                ->assertForbidden();
        }

        $this->assertSame([], $this->events);
    }

    public function test_store_rejects_a_proxy_token_without_the_dedicated_scope(): void
    {
        $this->withToken($this->proxyToken(['study:write']))
            ->postJson('/api/convolab/tools/analytics', $this->validPayload())
            ->assertForbidden();

        $this->assertSame([], $this->events);
    }

    public function test_store_rejects_the_proxy_token_when_the_account_is_not_configured(): void
    {
        config()->set('services.convolab.proxy_user_email');

        $this->withToken($this->proxyToken(['tools:analytics']))
            ->postJson('/api/convolab/tools/analytics', $this->validPayload())
            ->assertForbidden();

        $this->assertSame([], $this->events);
    }

    public function test_store_records_the_legacy_json_line_shape(): void
    {
        $token = $this->proxyToken(['tools:analytics']);

        Carbon::setTestNow('2026-07-22 18:15:12.345 UTC');
        try {
            $this->withToken($token)
                ->postJson('/api/convolab/tools/analytics', $this->validPayload())
                ->assertNoContent();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertCount(1, $this->events);
        $properties = $this->events[0]['properties'];
        unset($this->events[0]['properties']);

        $this->assertSame([
            'type' => 'tool_analytics',
            'at' => '2026-07-22T18:15:12.345Z',
            'tool' => 'kana:trainer',
            'event' => 'answer_passed',
            'context' => 'app',
            'mode' => 'fsrs',
            'sessionId' => 'session_123',
        ], $this->events[0]);
        $this->assertInstanceOf(\stdClass::class, $properties);
        $this->assertEquals((object) [
            'correct' => true,
            'duration_ms' => 1250,
            'source' => 'keyboard',
            'score' => 0.98,
            'detail' => null,
        ], $properties);
    }

    public function test_store_omits_nullable_dimensions_and_keeps_empty_properties_an_object(): void
    {
        $payload = $this->validPayload();
        $payload['mode'] = null;
        $payload['sessionId'] = null;
        $payload['properties'] = [];

        $this->withToken($this->proxyToken(['tools:analytics']))
            ->postJson('/api/convolab/tools/analytics', $payload)
            ->assertNoContent();

        $this->assertArrayNotHasKey('mode', $this->events[0]);
        $this->assertArrayNotHasKey('sessionId', $this->events[0]);
        $this->assertSame('{}', json_encode($this->events[0]['properties']));
    }

    public function test_store_keeps_numeric_property_keys_in_a_json_object(): void
    {
        $payload = $this->validPayload();
        $payload['properties'] = ['0' => true];

        $this->withToken($this->proxyToken(['tools:analytics']))
            ->postJson('/api/convolab/tools/analytics', $payload)
            ->assertNoContent();

        $this->assertSame('{"0":true}', json_encode($this->events[0]['properties']));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('invalidPayloads')]
    public function test_store_rejects_invalid_bounded_contracts(
        array $changes,
        string $field,
    ): void {
        $this->withToken($this->proxyToken(['tools:analytics']))
            ->postJson(
                '/api/convolab/tools/analytics',
                array_replace($this->validPayload(), $changes),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame([], $this->events);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing tool' => [['tool' => null], 'tool'],
            'unsafe tool token' => [['tool' => 'kana trainer'], 'tool'],
            'long event token' => [['event' => str_repeat('x', 81)], 'event'],
            'unknown context' => [['context' => 'admin'], 'context'],
            'unknown mode' => [['mode' => 'guided'], 'mode'],
            'unsafe session id' => [['sessionId' => "line\nbreak"], 'sessionId'],
            'too many properties' => [[
                'properties' => array_fill_keys(
                    array_map(fn (int $index): string => 'key_'.$index, range(1, 17)),
                    true,
                ),
            ], 'properties'],
            'unsafe property key' => [['properties' => ['bad key' => true]], 'properties'],
            'long property key' => [[
                'properties' => [str_repeat('k', 41) => true],
            ], 'properties'],
            'long property string' => [[
                'properties' => ['detail' => str_repeat('x', 121)],
            ], 'properties'],
            'nested property value' => [['properties' => ['detail' => ['nested']]], 'properties'],
        ];
    }

    public function test_store_ignores_unknown_top_level_fields(): void
    {
        $payload = $this->validPayload();
        $payload['internal'] = 'not-logged';

        $this->withToken($this->proxyToken(['tools:analytics']))
            ->postJson('/api/convolab/tools/analytics', $payload)
            ->assertNoContent();

        $this->assertArrayNotHasKey('internal', $this->events[0]);
    }

    public function test_limiter_uses_an_operation_scoped_identity_bucket(): void
    {
        $this->assertSame(
            'tool-analytics-store:user:42',
            ToolAnalyticsRateLimiter::keyFor(42, '127.0.0.1'),
        );
        $this->assertSame(
            'tool-analytics-store:anon:127.0.0.1',
            ToolAnalyticsRateLimiter::keyFor(null, '127.0.0.1'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'tool' => 'kana:trainer',
            'event' => 'answer_passed',
            'context' => 'app',
            'mode' => 'fsrs',
            'sessionId' => 'session_123',
            'properties' => [
                'correct' => true,
                'duration_ms' => 1250,
                'source' => 'keyboard',
                'score' => 0.98,
                'detail' => null,
            ],
        ];
    }

    /**
     * @param  list<string>  $abilities
     */
    private function proxyToken(array $abilities): string
    {
        return User::factory()->create(['email' => 'proxy@example.com'])
            ->createToken('convolab-proxy', $abilities)
            ->plainTextToken;
    }
}
