<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Actions\PrepareStudyImportActiveSlotAction;
use LogicException;
use Tests\TestCase;

class PrepareStudyImportActiveSlotActionTest extends TestCase
{
    public function test_it_requires_an_active_database_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study import active-slot preparation must run inside a database transaction.');

        app(PrepareStudyImportActiveSlotAction::class)->handle(1, now());
    }
}
