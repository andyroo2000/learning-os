<?php

namespace App\Domain\Japanese\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JapaneseKnowledgeProfile extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['knowledge_version' => 'integer'];
    }

    public static function lockForUser(int $userId): self
    {
        self::query()->insertOrIgnore([
            'user_id' => $userId,
            'knowledge_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }
}
