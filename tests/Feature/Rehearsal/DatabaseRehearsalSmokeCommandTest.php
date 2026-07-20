<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use App\Support\Rehearsal\DatabaseRehearsalSmokeCheck;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use JsonException;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\TestCase;

class DatabaseRehearsalSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_command_exercises_read_oriented_api_shapes_for_a_selected_user(): void
    {
        $user = $this->rehearsalUser();

        $this->artisan('rehearsal:smoke', [
            '--user-email' => $user->email,
        ])
            ->expectsOutputToContain('[PASS] database connection')
            ->expectsOutputToContain('[PASS] migrations')
            ->expectsOutputToContain('[PASS] auth user')
            ->expectsOutputToContain('[PASS] temporary token')
            ->expectsOutputToContain('[PASS] current user')
            ->expectsOutputToContain('[PASS] study settings')
            ->expectsOutputToContain('[PASS] study overview')
            ->expectsOutputToContain('[PASS] study browser')
            ->expectsOutputToContain('[PASS] study new queue')
            ->expectsOutputToContain('[PASS] study imports')
            ->expectsOutputToContain('[PASS] current study import')
            ->expectsOutputToContain('[PASS] content episodes')
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

    public function test_smoke_command_treats_blank_user_email_as_omitted(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $this->artisan('rehearsal:smoke', [
            '--user-email' => '   ',
        ])
            ->expectsOutputToContain('[PASS] auth user - Selected first available user [ada@example.com].')
            ->assertExitCode(0);
    }

    public function test_smoke_command_refuses_to_run_in_production_without_explicit_override(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->rehearsalUser();

        $this->artisan('rehearsal:smoke', [
            '--user-email' => 'ada@example.com',
        ])
            ->expectsOutputToContain('This command must not run in production without --allow-production.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => DatabaseRehearsalSmokeCheck::TOKEN_NAME,
        ]);
    }

    public function test_smoke_command_can_run_in_production_with_explicit_override(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->rehearsalUser();

        $this->artisan('rehearsal:smoke', [
            '--user-email' => 'ada@example.com',
            '--allow-production' => true,
        ])
            ->expectsOutputToContain('WARNING: Running smoke check against a production database.')
            ->expectsOutputToContain('Smoke check passed.')
            ->assertExitCode(0);
    }

    public function test_smoke_command_checks_registered_migration_paths(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);
        $migrationDirectory = storage_path('framework/testing/rehearsal-migrations');
        $migrationFile = $migrationDirectory.'/2099_01_01_000000_create_external_rehearsal_table.php';

        if (! is_dir($migrationDirectory)) {
            mkdir($migrationDirectory, 0777, true);
        }

        file_put_contents($migrationFile, '<?php');
        $this->app->make(Migrator::class)->path($migrationDirectory);

        try {
            $this->artisan('rehearsal:smoke', [
                '--user-email' => $user->email,
            ])
                ->expectsOutputToContain('[FAIL] migrations - Pending migrations were found. Run php artisan migrate first.')
                ->expectsOutputToContain('2099_01_01_000000_create_external_rehearsal_table')
                ->assertExitCode(1);
        } finally {
            @unlink($migrationFile);
            @rmdir($migrationDirectory);
        }
    }

    public function test_smoke_command_fails_when_migration_records_have_no_matching_files(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_missing_rehearsal_migration',
            'batch' => 999,
        ]);

        $this->artisan('rehearsal:smoke', [
            '--user-email' => 'ada@example.com',
        ])
            ->expectsOutputToContain('[FAIL] migrations - Migration records were found without matching migration files.')
            ->expectsOutputToContain('2099_01_01_000000_missing_rehearsal_migration')
            ->assertExitCode(1);
    }

    public function test_smoke_command_reports_temporary_token_cleanup_failure(): void
    {
        $user = $this->rehearsalUser();
        $originalDispatcher = PersonalAccessToken::getEventDispatcher();
        PersonalAccessToken::setEventDispatcher(new Dispatcher($this->app));

        PersonalAccessToken::deleting(function (): never {
            throw new RuntimeException('cleanup failed');
        });

        try {
            $this->artisan('rehearsal:smoke', [
                '--user-email' => $user->email,
            ])
                ->expectsOutputToContain('[WARN] token cleanup - Unable to delete the temporary smoke-check token; it will expire automatically.')
                ->expectsOutputToContain('Smoke check passed.')
                ->assertExitCode(0);

            $this->assertDatabaseHas('personal_access_tokens', [
                'name' => DatabaseRehearsalSmokeCheck::TOKEN_NAME,
                'abilities' => '["smoke:read"]',
            ]);
        } finally {
            if ($originalDispatcher === null) {
                PersonalAccessToken::unsetEventDispatcher();
            } else {
                PersonalAccessToken::setEventDispatcher($originalDispatcher);
            }
        }
    }

    /**
     * @throws JsonException
     */
    public function test_smoke_command_can_emit_json_report(): void
    {
        $user = $this->rehearsalUser();

        $exitCode = Artisan::call('rehearsal:smoke', [
            '--user-email' => $user->email,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['ok']);
        $this->assertSame('testing', $report['environment']);
        $this->assertArrayHasKey('connection', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertContains('current user', array_column($report['checks'], 'name'));
    }

    /**
     * @throws JsonException
     */
    public function test_smoke_command_can_emit_json_failure_report(): void
    {
        $exitCode = Artisan::call('rehearsal:smoke', [
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertArrayHasKey('connection', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertContains('auth user', array_column($report['checks'], 'name'));
    }

    private function rehearsalUser(): User
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);
        StudySettings::factory()->for($user)->create();

        return $user;
    }
}
