<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\BuildStudyMediaBatchAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class BuildStudyMediaBatchActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_call_rejects_empty_oversized_duplicate_and_malformed_batches(): void
    {
        $action = resolve(BuildStudyMediaBatchAction::class);
        $id = strtolower((string) Str::ulid());

        foreach ([
            [],
            array_fill(0, BuildStudyMediaBatchAction::MAX_ITEMS + 1, $id),
            [$id, strtoupper($id)],
            ['not-an-id'],
            [$id, 123],
        ] as $ids) {
            try {
                $action->handle(1, $ids);
                $this->fail('Expected invalid direct batch input to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_direct_call_accepts_lower_and_upper_batch_bounds(): void
    {
        $action = resolve(BuildStudyMediaBatchAction::class);
        $ids = collect(range(1, BuildStudyMediaBatchAction::MAX_ITEMS))
            ->map(fn (): string => strtolower((string) Str::ulid()))
            ->all();

        $this->assertSame([], $action->handle(1, [$ids[0]]));
        $this->assertSame([], $action->handle(1, $ids));
    }
}
