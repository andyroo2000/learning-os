<?php

namespace App\Domain\Study\Support;

use InvalidArgumentException;

final readonly class StudyActivitySourceKey
{
    public const HASH_LENGTH = 64;

    public const MAX_COMPONENT_LENGTH = 1024;

    private function __construct(public string $value) {}

    public static function forGoogleCalendar(
        string $providerAccountId,
        string $calendarId,
        string $eventId,
        ?string $eventInstanceId = null,
    ): self {
        $components = [
            'google_calendar:v1',
            self::component($providerAccountId),
            self::component($calendarId),
            self::component($eventId),
            self::component($eventInstanceId ?? $eventId),
        ];
        $payload = implode('', array_map(
            static fn (string $component): string => strlen($component).':'.$component,
            $components,
        ));

        return new self(hash('sha256', $payload));
    }

    private static function component(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_COMPONENT_LENGTH) {
            throw new InvalidArgumentException(
                'Study activity external identity components must contain 1 to 1024 characters.',
            );
        }

        return $value;
    }
}
