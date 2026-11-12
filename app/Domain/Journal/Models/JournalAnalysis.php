<?php

namespace App\Domain\Journal\Models;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use Database\Factories\JournalAnalysisFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalAnalysis extends Model
{
    /** @use HasFactory<JournalAnalysisFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'queued'];

    protected static function newFactory(): JournalAnalysisFactory
    {
        return JournalAnalysisFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (JournalAnalysis $analysis): void {
            if ($analysis->isDirty(['entry_id', 'source_version', 'source_body'])) {
                throw new LogicException('A journal analysis source cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source_version' => 'integer',
            'status' => JournalAnalysisStatus::class,
            'result' => 'array',
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }
}
