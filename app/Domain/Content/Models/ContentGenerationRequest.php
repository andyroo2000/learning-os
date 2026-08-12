<?php

namespace App\Domain\Content\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentGenerationRequest extends Model
{
    /** After this replay window, a reused client request ID starts a new request. */
    public const TERMINAL_RETENTION_DAYS = 30;

    protected $table = 'content_generation_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'job_attempt' => 'integer',
            'response_status' => 'integer',
            'dispatch_claimed_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
