<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyPile;
use App\Domain\Study\Services\PersistStudyPileRecord;
use App\Domain\Study\Support\StudyPileAccess;
use App\Domain\Study\Support\StudyPileInput;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class SaveStudyPileAction
{
    public function __construct(private readonly PersistStudyPileRecord $persist) {}

    public function handle(int $userId, string $pileId, array $attributes): StudyPile
    {
        $pileId = StudyPileAccess::id($pileId);
        $attributes = StudyPileInput::validated($attributes);

        return DB::transaction(function () use ($userId, $pileId, $attributes): StudyPile {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $pile = StudyPile::query()->find($pileId);
            if ($pile !== null && (int) $pile->user_id !== $userId) {
                throw (new ModelNotFoundException)->setModel(StudyPile::class);
            }
            $pile ??= new StudyPile;
            $pile->id = $pileId;
            $pile->user_id = $userId;
            $this->persist->handle($userId, $pile, $attributes);

            return $pile->load('chapters');
        });
    }
}
