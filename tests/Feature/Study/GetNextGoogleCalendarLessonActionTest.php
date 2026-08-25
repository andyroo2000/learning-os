<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\GetNextGoogleCalendarLessonAction;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetNextGoogleCalendarLessonActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_earliest_owned_future_title_match(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($owner);
        $otherConnection = $this->connection($other, 'other-account');
        $now = CarbonImmutable::parse('2026-08-24T18:00:00Z');

        $this->mirror($connection, 'ongoing', 'iTalki', $now->subMinutes(10), $now->addMinutes(20));
        $this->mirror($connection, 'unmatched', 'Dentist', $now->addMinutes(10), $now->addMinutes(40));
        $this->mirror($connection, 'other-calendar', 'iTalki', $now->addMinutes(20), $now->addMinutes(50), 'other');
        $this->mirror($otherConnection, 'other-user', 'iTalki', $now->addMinutes(30), $now->addHour());
        $this->mirror($connection, 'later', 'Japanese lesson', $now->addHours(2), $now->addHours(3));
        $this->mirror($connection, 'next', 'iTalki with Yuki', $now->addHour(), $now->addHours(2));

        $settings = GoogleCalendarSettings::make(['primary'], ['iTalki', 'Japanese lesson'], true);
        $result = app(GetNextGoogleCalendarLessonAction::class)->handle($connection, $settings, $now);

        $this->assertSame([
            'title' => 'iTalki with Yuki',
            'startsAt' => '2026-08-24T19:00:00Z',
            'endsAt' => '2026-08-24T20:00:00Z',
        ], $result);
    }

    private function connection(User $user, string $account = 'account'): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id,
            'provider_account_id' => $account,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'],
            'settings' => [],
            'connected_at' => now(),
        ]);
    }

    private function mirror(
        GoogleCalendarConnection $connection,
        string $eventID,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $calendarID = 'primary',
    ): void {
        GoogleCalendarEventMirror::query()->forceCreate([
            'google_calendar_connection_id' => $connection->id,
            'source_key' => hash('sha256', $connection->id.'|'.$eventID),
            'calendar_id' => $calendarID,
            'provider_event_id' => $eventID,
            'status' => 'confirmed',
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => false,
            'observed_at' => $startsAt,
        ]);
    }
}
