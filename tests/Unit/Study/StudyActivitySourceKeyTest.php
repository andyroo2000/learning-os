<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyActivitySourceKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyActivitySourceKeyTest extends TestCase
{
    public function test_google_calendar_keys_are_fixed_deterministic_and_scoped_to_the_event_instance(): void
    {
        $key = StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event', 'instance');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->value);
        $this->assertSame(
            $key->value,
            StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event', 'instance')->value,
        );
        $this->assertNotSame(
            $key->value,
            StudyActivitySourceKey::forGoogleCalendar('other-account', 'calendar', 'event', 'instance')->value,
        );
        $this->assertNotSame(
            $key->value,
            StudyActivitySourceKey::forGoogleCalendar('account', 'other-calendar', 'event', 'instance')->value,
        );
        $this->assertNotSame(
            $key->value,
            StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'other-event', 'instance')->value,
        );
        $this->assertNotSame(
            $key->value,
            StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event', 'other-instance')->value,
        );
    }

    public function test_it_accepts_google_identity_components_at_the_1024_character_limit(): void
    {
        $maximum = str_repeat('日', StudyActivitySourceKey::MAX_COMPONENT_LENGTH);

        $key = StudyActivitySourceKey::forGoogleCalendar($maximum, $maximum, $maximum, $maximum);

        $this->assertSame(StudyActivitySourceKey::HASH_LENGTH, strlen($key->value));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function invalidComponentProvider(): array
    {
        $tooLong = str_repeat('x', StudyActivitySourceKey::MAX_COMPONENT_LENGTH + 1);

        return [
            'provider account' => [$tooLong, 'calendar', 'event', 'instance'],
            'calendar' => ['account', $tooLong, 'event', 'instance'],
            'event' => ['account', 'calendar', $tooLong, 'instance'],
            'event instance' => ['account', 'calendar', 'event', $tooLong],
            'blank event' => ['account', 'calendar', " \t\n ", 'instance'],
        ];
    }

    #[DataProvider('invalidComponentProvider')]
    public function test_it_rejects_invalid_google_identity_components(
        string $account,
        string $calendar,
        string $event,
        string $instance,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        StudyActivitySourceKey::forGoogleCalendar($account, $calendar, $event, $instance);
    }
}
