<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendar;

final class ListReadableGoogleCalendarsAction
{
    private const MAX_CALENDARS = 250;

    private const MAX_PAGES = 4;

    private const READABLE_ROLES = ['reader', 'writer', 'owner'];

    public function __construct(
        private GetGoogleCalendarAccessTokenAction $accessToken,
        private GoogleCalendarReadTransport $google,
    ) {}

    /** @return array{calendars:list<array{id:string,name:string,primary:bool}>,truncated:bool} */
    public function handle(int $userId, ?string $resolvedAccessToken = null): array
    {
        $token = $resolvedAccessToken ?? $this->accessToken->handle($userId);
        $pageToken = null;
        $calendars = [];
        $seen = [];
        $truncated = false;
        for ($pageNumber = 0; $pageNumber < self::MAX_PAGES; $pageNumber++) {
            $page = $this->google->calendars($token, $pageToken, 250);
            foreach ($page->items as $calendar) {
                if (! $calendar instanceof GoogleCalendar || ! in_array($calendar->accessRole, self::READABLE_ROLES, true) || isset($seen[$calendar->id])) {
                    continue;
                }
                if (count($calendars) === self::MAX_CALENDARS) {
                    $truncated = true;
                    break 2;
                }
                $seen[$calendar->id] = true;
                $calendars[] = ['id' => $calendar->id, 'name' => $calendar->summary, 'primary' => $calendar->primary];
            }
            $pageToken = $page->nextPageToken;
            if ($pageToken === null) {
                break;
            }
            if (count($calendars) === self::MAX_CALENDARS || $pageNumber === self::MAX_PAGES - 1) {
                $truncated = true;
                break;
            }
        }
        usort($calendars, static fn (array $left, array $right): int => ($right['primary'] <=> $left['primary'])
            ?: (mb_strtolower($left['name'], 'UTF-8') <=> mb_strtolower($right['name'], 'UTF-8'))
            ?: ($left['id'] <=> $right['id']));

        return ['calendars' => $calendars, 'truncated' => $truncated];
    }
}
