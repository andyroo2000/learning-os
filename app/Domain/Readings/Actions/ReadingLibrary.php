<?php

namespace App\Domain\Readings\Actions;

use App\Domain\Readings\Models\Reading;
use Illuminate\Database\Eloquent\Collection;

final class ReadingLibrary
{
    public function forUser(int $userId): Collection
    {
        return Reading::query()->where('user_id', $userId)->orderByDesc('updated_at')->orderBy('id')->get();
    }

    public function owned(int $userId, string $id): Reading
    {
        return Reading::query()->where('user_id', $userId)->findOrFail(strtolower($id));
    }
}
