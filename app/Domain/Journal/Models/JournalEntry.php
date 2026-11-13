<?php

namespace App\Domain\Journal\Models;

use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Models\User;
use App\Policies\JournalEntryPolicy;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

#[UsePolicy(JournalEntryPolicy::class)]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings, SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['version' => 1];

    // Actions explicitly assign validated fields; ownership and versions are never mass assigned.
    protected $guarded = ['*'];

    protected static function newFactory(): JournalEntryFactory
    {
        return JournalEntryFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry): void {
            if ($entry->isDirty('user_id')) {
                throw new LogicException('Journal entry owner cannot be changed.');
            }
            if ($entry->isDirty(['root_entry_id', 'question_id', 'conversation_position'])) {
                throw new LogicException('A journal reply cannot be moved to another conversation.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'entry_date' => 'immutable_date:Y-m-d',
            'version' => 'integer',
        ];
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(JournalAnalysis::class, 'entry_id')->latestOfMany('source_version');
    }

    public function answeredQuestion(): BelongsTo
    {
        return $this->belongsTo(JournalQuestion::class, 'question_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
