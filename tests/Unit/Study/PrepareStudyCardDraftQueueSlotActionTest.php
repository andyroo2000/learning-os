<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Actions\PrepareStudyCardDraftQueueSlotAction;
use LogicException;
use Tests\TestCase;

class PrepareStudyCardDraftQueueSlotActionTest extends TestCase
{
    public function test_it_requires_an_active_database_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft queue-slot preparation must run inside a database transaction.');

        app(PrepareStudyCardDraftQueueSlotAction::class)->handle(1);
    }
}
