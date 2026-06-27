<?php

namespace Tests\Feature\Rehearsal;

use App\Models\User;
use App\Support\Rehearsal\DatabaseRehearsalSmokeCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use JsonException;
use Tests\TestCase;

class DatabaseRehearsalSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_command_exercises_read_oriented_api_shapes_for_a_selected_user(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $this->artisan('rehearsal:smoke', [
            '--user-email' => $user->email,
        ])
            ->expectsOutputToContain('[PASS] database connection')
            ->expectsOutputToContain('[PASS] migrations')
            ->expectsOutputToContain('[PASS] auth user')
            ->expectsOutputToContain('[PASS] current user')
            ->expectsOutputToContain('[PASS] study settings')
            ->expectsOutputToContain('[PASS] study overview')
            ->expectsOutputToContain('[PASS] study browser')
            ->expectsOutputToContain('[PASS] study new queue')
            ->expectsOutputToContain('[PASS] study imports')
            ->expectsOutputToContain('[PASS] current study import')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => DatabaseRehearsalSmokeCheck::TOKEN_NAME,
        ]);
    }

    public function test_smoke_command_fails_when_the_requested_user_does_not_exist(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $this->artisan('rehearsal:smoke', [
            '--user-email' => 'missing@example.com',
        ])
            ->expectsOutputToContain('[FAIL] auth user - No user exists with email [missing@example.com].')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => DatabaseRehearsalSmokeCheck::TOKEN_NAME,
        ]);
    }

    public function test_smoke_command_fails_when_no_user_can_be_selected(): void
    {
        $this->artisan('rehearsal:smoke')
            ->expectsOutputToContain('[FAIL] auth user - No users exist in the configured database.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => DatabaseRehearsalSmokeCheck::TOKEN_NAME,
        ]);
    }

    /**
     * @throws JsonException
     */
    public function test_smoke_command_can_emit_json_report(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $exitCode = Artisan::call('rehearsal:smoke', [
            '--user-email' => $user->email,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['ok']);
        $this->assertArrayHasKey('connection', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertContains('current user', array_column($report['checks'], 'name'));
    }
}
