<?php

namespace Tests\Feature\Rehearsal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportTransactionTest extends ConvoLabRehearsalImportTestCase
{
    public function test_truncate_and_import_roll_back_together_when_a_late_mapping_fails(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('study_review_logs')->update(['rating' => 9]);
        $existingUser = User::factory()->create();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Unsupported Convo Lab review rating [9].')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $existingUser->id]);
        $this->assertDatabaseCount('cards', 0);
    }
}
