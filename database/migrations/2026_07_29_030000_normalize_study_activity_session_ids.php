<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('study_activity_sessions')
                ->select(['id', 'user_id', 'client_session_id'])
                ->orderBy('user_id')
                ->orderBy('id')
                ->get()
                ->groupBy(function (object $row): string {
                    return $row->user_id."\0".$this->normalize($row->client_session_id);
                })
                ->each(function ($rows): void {
                    $canonicalId = $this->normalize($rows->first()->client_session_id);
                    $keeper = $rows->first(
                        fn (object $row): bool => $row->client_session_id === $canonicalId,
                    ) ?? $rows->first();
                    $duplicateIds = $rows
                        ->reject(fn (object $row): bool => $row->id === $keeper->id)
                        ->pluck('id');

                    if ($duplicateIds->isNotEmpty()) {
                        DB::table('study_activity_sessions')
                            ->whereIn('id', $duplicateIds)
                            ->delete();
                    }

                    if ($keeper->client_session_id !== $canonicalId) {
                        DB::table('study_activity_sessions')
                            ->where('id', $keeper->id)
                            ->update(['client_session_id' => $canonicalId]);
                    }
                });
        });
    }

    public function down(): void
    {
        // Original identifier casing cannot be reconstructed after canonicalization.
    }

    private function normalize(string $value): string
    {
        if (Str::isUlid($value)) {
            return strtoupper($value);
        }

        if (Str::isUuid($value)) {
            return strtolower($value);
        }

        return $value;
    }
};
