<?php

namespace App\Domain\Journal\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalQuestion extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected $attributes = ['audio_status' => 'idle'];

    protected function casts(): array
    {
        return ['tutor' => 'array', 'audio_requested_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalQuestion $question): void {
            $immutable = ['analysis_id', 'source_key', 'text', 'reading', 'translation'];
            if ($question->getOriginal('tutor') !== null) {
                $immutable[] = 'tutor';
            }
            if ($question->isDirty($immutable)) {
                throw new LogicException('A question and its assigned tutor cannot be changed.');
            }
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(JournalAnalysis::class);
    }
}
