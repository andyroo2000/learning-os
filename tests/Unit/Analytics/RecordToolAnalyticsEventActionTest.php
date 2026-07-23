<?php

namespace Tests\Unit\Analytics;

use App\Domain\Analytics\Actions\RecordToolAnalyticsEventAction;
use App\Domain\Analytics\Contracts\ToolAnalyticsLogger;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecordToolAnalyticsEventActionTest extends TestCase
{
    public function test_action_builds_the_legacy_event_shape_for_a_direct_caller(): void
    {
        $logger = new CapturingToolAnalyticsLogger;
        $action = new RecordToolAnalyticsEventAction($logger);

        Carbon::setTestNow('2026-07-22 18:15:12.345 UTC');
        try {
            $action->handle([
                'tool' => 'kana',
                'event' => 'opened',
                'context' => 'public',
                'properties' => ['path' => '/tools/kana'],
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('tool_analytics', $logger->event['type']);
        $this->assertSame('2026-07-22T18:15:12.345Z', $logger->event['at']);
        $this->assertSame('kana', $logger->event['tool']);
        $this->assertSame('opened', $logger->event['event']);
        $this->assertSame('public', $logger->event['context']);
        $this->assertArrayNotHasKey('mode', $logger->event);
        $this->assertArrayNotHasKey('sessionId', $logger->event);
        $this->assertSame(
            '{"path":"/tools/kana"}',
            json_encode($logger->event['properties'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_action_does_not_hide_logger_failures(): void
    {
        $action = new RecordToolAnalyticsEventAction(
            new class implements ToolAnalyticsLogger
            {
                public function write(array $event): void
                {
                    throw new RuntimeException('output unavailable');
                }
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('output unavailable');

        $action->handle([
            'tool' => 'kana',
            'event' => 'opened',
            'context' => 'public',
        ]);
    }
}

final class CapturingToolAnalyticsLogger implements ToolAnalyticsLogger
{
    /**
     * @var array<string, mixed>
     */
    public array $event = [];

    public function write(array $event): void
    {
        $this->event = $event;
    }
}
