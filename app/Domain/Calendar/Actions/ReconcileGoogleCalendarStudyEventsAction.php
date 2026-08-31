<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\GoogleCalendarStudyEvent;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ReconcileGoogleCalendarStudyEventsAction
{
    private const CHUNK_SIZE = 250;

    public function __construct(private UpsertStudyActivitySessionsAction $upsert) {}

    /** @return array{upserted:int,deleted:int} */
    public function handle(int $userId, GoogleCalendarConnection $connection, ?string $syncRunId = null, bool $allowDisabled = false): array
    {
        return DB::transaction(function () use ($userId, $connection, $syncRunId, $allowDisabled): array {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $locked = GoogleCalendarConnection::query()
                ->whereKey($connection->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return ['upserted' => 0, 'deleted' => 0];
            }
            if ((int) $locked->user_id !== $userId) {
                throw (new ModelNotFoundException)->setModel(GoogleCalendarConnection::class);
            }
            if ($syncRunId !== null && $locked->sync_run_id !== $syncRunId) {
                return ['upserted' => 0, 'deleted' => 0];
            }
            $settings = GoogleCalendarSettings::fromStored($locked->settings);
            if ($settings === null || (! $allowDisabled && ! $settings->syncEnabled)) {
                return ['upserted' => 0, 'deleted' => 0];
            }

            $result = ['upserted' => 0, 'deleted' => 0];
            $now = CarbonImmutable::instance(now())->utc();
            // The bounded mirror window is scanned twice so canonical calendar
            // selection remains stable even when Google forces a mirror reset.
            $sourcesByCalendar = [];
            $locked->eventMirrors()
                ->whereIn('calendar_id', $settings->calendarIds)
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function (Collection $mirrors) use ($settings, $now, &$sourcesByCalendar): void {
                    foreach ($mirrors as $mirror) {
                        $event = GoogleCalendarStudyEvent::fromMirror($mirror, $settings, $now);
                        if ($event === null) {
                            continue;
                        }
                        $deduplicationKey = $event->deduplicationKey();
                        $calendarSource = $sourcesByCalendar[$deduplicationKey][$mirror->calendar_id] ?? null;
                        if ($calendarSource === null || strcmp($event->sourceKey->value, $calendarSource) < 0) {
                            // Source keys survive mirror resets, unlike auto-increment IDs.
                            $sourcesByCalendar[$deduplicationKey][$mirror->calendar_id] = $event->sourceKey->value;
                        }
                    }
                });
            $canonicalCalendars = [];
            foreach ($sourcesByCalendar as $deduplicationKey => $calendarSources) {
                if (count($calendarSources) < 2) {
                    continue;
                }
                $canonicalSource = min($calendarSources);
                $canonicalCalendars[$deduplicationKey] = (string) array_search($canonicalSource, $calendarSources, true);
            }
            $locked->eventMirrors()
                ->whereIn('calendar_id', $settings->calendarIds)
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function (Collection $mirrors) use ($settings, $now, $userId, &$result, $canonicalCalendars): void {
                    $sessions = [];
                    $deleteKeys = [];
                    foreach ($mirrors as $mirror) {
                        $event = GoogleCalendarStudyEvent::fromMirror($mirror, $settings, $now);
                        if ($event === null) {
                            $deleteKeys[] = $mirror->source_key;

                            continue;
                        }
                        $deduplicationKey = $event->deduplicationKey();
                        $canonicalCalendar = $canonicalCalendars[$deduplicationKey] ?? null;
                        if ($canonicalCalendar !== null && $mirror->calendar_id !== $canonicalCalendar) {
                            $deleteKeys[] = $mirror->source_key;

                            continue;
                        }
                        $sessions[] = new StudyActivitySessionData(
                            clientSessionId: 'google-calendar:'.substr($event->sourceKey->value, 0, 48),
                            activity: StudyActivityKind::Conversation,
                            source: StudyActivitySource::Calendar,
                            name: $event->ledgerName(),
                            startedAt: $event->startsAt,
                            endedAt: $event->endsAt,
                            durationMs: $event->durationMs,
                            audioPlaybackMs: null,
                            cardsCreated: null,
                            origin: StudyActivityOrigin::GoogleCalendar,
                            sourceKey: $event->sourceKey,
                        );
                    }
                    if ($sessions !== []) {
                        $result['upserted'] += $this->upsert->handle($userId, $sessions)
                            ->where('source', StudyActivitySource::Calendar)
                            ->count();
                    }
                    if ($deleteKeys !== []) {
                        $result['deleted'] += StudyActivitySession::query()
                            ->where('user_id', $userId)
                            ->where('origin', StudyActivityOrigin::GoogleCalendar)
                            ->where('source', StudyActivitySource::Calendar)
                            ->whereIn('source_key', $deleteKeys)
                            ->delete();
                    }
                });

            return $result;
        });
    }
}
