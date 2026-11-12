<?php

namespace App\Domain\Journal\Models;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use Database\Factories\JournalReplyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalReply extends Model
{
    /** @use HasFactory<JournalReplyFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'queued'];

    protected static function newFactory(): JournalReplyFactory
    {
        return JournalReplyFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (JournalReply $reply): void {
            if ($reply->isDirty(['analysis_id', 'parent_id', 'base_revision', 'replaces_answer', 'answer'])) {
                throw new LogicException('A journal reply source cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => JournalAnalysisStatus::class, 'result' => 'array', 'base_revision' => 'integer',
            'replaces_answer' => 'boolean', 'processing_started_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<JournalAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(JournalAnalysis::class);
    }
}
