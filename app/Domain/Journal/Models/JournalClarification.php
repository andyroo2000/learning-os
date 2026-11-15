<?php

namespace App\Domain\Journal\Models;

use App\Domain\Journal\Enums\JournalClarificationStatus;
use Database\Factories\JournalClarificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalClarification extends Model
{
    /** @use HasFactory<JournalClarificationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'queued', 'revision' => 0, 'answers' => '[]'];

    protected static function newFactory(): JournalClarificationFactory
    {
        return JournalClarificationFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (JournalClarification $clarification): void {
            if ($clarification->isDirty(['entry_id', 'source_version', 'source_body'])) {
                throw new LogicException('A clarification belongs to one immutable journal draft revision.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source_version' => 'integer',
            'revision' => 'integer',
            'status' => JournalClarificationStatus::class,
            'question' => 'array',
            'answers' => 'array',
            'processing_started_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }
}
