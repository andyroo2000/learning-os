<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyPile;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class StudyPileAccess
{
    public static function id(string $id): string
    {
        $normalized = CanonicalUlid::normalize($id);
        if (! Str::isUlid($normalized)) {
            throw (new ModelNotFoundException)->setModel(StudyPile::class);
        }

        return $normalized;
    }

    public static function owned(int $userId, string $pileId): StudyPile
    {
        return StudyPile::query()->where('user_id', $userId)->whereKey(self::id($pileId))->firstOrFail();
    }
}
