<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use Tests\TestCase;

class GoogleCalendarSettingsTest extends TestCase
{
    public function test_it_canonicalizes_exact_ids_and_unicode_case_insensitive_terms(): void
    {
        $settings = GoogleCalendarSettings::make([' primary ', 'primary', 'PRIMARY'], ["\u{00A0}iTalki\u{3000}", 'ITALKI', 'ÄBC', 'äbc'], true);

        $this->assertSame([
            'calendarIds' => ['primary', 'PRIMARY'],
            'titleMatchTerms' => ['iTalki', 'ÄBC'],
            'syncEnabled' => true,
        ], $settings->toArray());
        $this->assertTrue($settings->matchesTitle('Weekly ITALKI lesson'));
        $this->assertTrue($settings->matchesTitle('weekly äbc practice'));
        $this->assertFalse($settings->matchesTitle('Dentist appointment'));
    }

    public function test_stored_settings_require_the_complete_flat_contract(): void
    {
        $this->assertNull(GoogleCalendarSettings::fromStored(['calendarIds' => ['primary'], 'syncEnabled' => true]));
        $this->assertNull(GoogleCalendarSettings::fromStored(['calendarIds' => ['primary'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true, 'extra' => 1]));
        $this->assertNull(GoogleCalendarSettings::fromStored(['calendarIds' => [str_repeat('a', 1025)], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true]));
        $this->assertSame(['calendarIds' => ['primary'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => false], GoogleCalendarSettings::fromStored([
            'syncEnabled' => false, 'titleMatchTerms' => ['lesson'], 'calendarIds' => ['primary'],
        ])?->toArray());
    }
}
