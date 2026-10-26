<?php

namespace App\Domain\Study\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyPileChapter extends Model
{
    use HasUlids;

    protected $attributes = ['is_active' => false, 'position' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<StudyPile, $this> */
    public function pile(): BelongsTo
    {
        return $this->belongsTo(StudyPile::class, 'pile_id');
    }
}
