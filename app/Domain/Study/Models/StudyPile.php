<?php

namespace App\Domain\Study\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyPile extends Model
{
    use HasUlids;

    protected $attributes = ['is_active' => false, 'position' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    /** @return HasMany<StudyPileChapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(StudyPileChapter::class, 'pile_id')->orderBy('position')->orderBy('id');
    }
}
