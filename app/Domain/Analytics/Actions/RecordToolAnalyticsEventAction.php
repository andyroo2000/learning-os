<?php

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Contracts\ToolAnalyticsLogger;
use Illuminate\Support\Carbon;
use stdClass;

final class RecordToolAnalyticsEventAction
{
    public function __construct(private ToolAnalyticsLogger $logger) {}

    /**
     * @param  array{
     *     tool: string,
     *     event: string,
     *     context: string,
     *     mode?: string|null,
     *     sessionId?: string|null,
     *     properties?: array<string, bool|float|int|string|null>
     * }  $attributes
     */
    public function handle(array $attributes): void
    {
        $event = [
            'type' => 'tool_analytics',
            'at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'tool' => $attributes['tool'],
            'event' => $attributes['event'],
            'context' => $attributes['context'],
        ];

        if (($attributes['mode'] ?? null) !== null) {
            $event['mode'] = $attributes['mode'];
        }

        if (($attributes['sessionId'] ?? null) !== null) {
            $event['sessionId'] = $attributes['sessionId'];
        }

        $event['properties'] = new stdClass;
        foreach ($attributes['properties'] ?? [] as $key => $value) {
            $event['properties']->{(string) $key} = $value;
        }

        $this->logger->write($event);
    }
}
