<?php

namespace App\Domain\Readings\Actions;

use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReadingProgress
{
    public function __construct(private readonly ReadingLibrary $library) {}

    public function get(string $id): ?array
    {
        $row = DB::table('reading_progress')->where('reading_id', $id)->first();
        if (! $row) {
            return null;
        }

        return ['readingId' => $id, 'pageNumber' => (int) $row->page_number,
            'sentenceIndex' => $row->sentence_index === null ? null : (int) $row->sentence_index,
            'readingRevision' => (int) $row->reading_revision, 'eventId' => $row->event_id,
            'readAt' => CarbonImmutable::parse($row->read_at, 'UTC')->toISOString()];
    }

    public function save(int $owner, string $id, array $data): array
    {
        return DB::transaction(function () use ($owner, $id, $data): array {
            User::query()->whereKey($owner)->lockForUpdate()->firstOrFail();
            $reading = $this->library->owned($owner, $id);
            $current = $this->get($reading->id);
            $date = CarbonImmutable::parse($data['readAt'])->utc();
            if ($this->alreadySuperseded($current, $data, $date)) {
                return $current;
            }
            $this->validatePosition($reading, $data);
            DB::table('reading_progress')->updateOrInsert(['reading_id' => $reading->id], [
                'page_number' => $data['pageNumber'], 'sentence_index' => $data['sentenceIndex'],
                'reading_revision' => $data['readingRevision'], 'event_id' => strtolower($data['eventId']),
                'read_at' => $date->format('Y-m-d H:i:s.v'),
            ]);

            return $this->get($reading->id);
        });
    }

    private function alreadySuperseded(?array $current, array $data, CarbonImmutable $date): bool
    {
        if (! $current) {
            return false;
        }
        if ($current['eventId'] === strtolower($data['eventId'])) {
            return true;
        }
        $previous = CarbonImmutable::parse($current['readAt']);

        return [$previous->format('Y-m-d H:i:s.v'), $current['eventId']]
            >= [$date->format('Y-m-d H:i:s.v'), strtolower($data['eventId'])];
    }

    private function validatePosition(Reading $reading, array $data): void
    {
        abort_unless($reading->revision === (int) $data['readingRevision'], 409, 'Refresh the reading before saving a position in its new revision.');
        $page = collect($reading->document['pages'])->firstWhere('number', (int) $data['pageNumber']);
        abort_unless($page, 422, 'This page is not in the reading.');
        if ($data['sentenceIndex'] === null) {
            return;
        }
        $parts = collect($page['columns'])->flatten(1)->all();
        $parts = [...$parts, ...($page['notes'] ?? [])];
        if (isset($page['biography'])) {
            $parts[] = $page['biography'];
        }
        abort_unless(in_array((int) $data['sentenceIndex'], array_column($parts, 0)), 422, 'This sentence is not on the selected page.');
    }
}
