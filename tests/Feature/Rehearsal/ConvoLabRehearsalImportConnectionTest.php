<?php

namespace Tests\Feature\Rehearsal;

use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportConnectionTest extends ConvoLabRehearsalImportTestCase
{
    public function test_rejects_a_source_connection_name_that_would_replace_the_target_connection(): void
    {
        $defaultConnection = DB::getDefaultConnection();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => $defaultConnection,
            '--source-database' => 'learning_os_convolab_source',
        ])
            ->expectsOutputToContain('Source connection name must differ from the target connection name.')
            ->assertExitCode(1);
    }
}
