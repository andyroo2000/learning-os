<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentAudioScriptAction
{
    public function handle(int $userId, string $convoLabUserId, string $episodeId): ContentAudioScript
    {
        return $this->ownedQuery($userId, $convoLabUserId, $episodeId)
            ->with($this->relations())
            ->firstOrFail();
    }

    public function locked(int $userId, string $convoLabUserId, string $episodeId): ContentAudioScript
    {
        return $this->ownedQuery($userId, $convoLabUserId, $episodeId)
            ->with('episode')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<string, \Closure> */
    public function relations(): array
    {
        return [
            'segments' => fn ($query) => $query->with('imageMedia')->orderBy('sort_order')->orderBy('id'),
            'renders' => fn ($query) => $query->orderBy('numeric_speed')->orderBy('id'),
        ];
    }

    private function ownedQuery(int $userId, string $convoLabUserId, string $episodeId)
    {
        $episodeId = ContentEpisodeId::normalize($episodeId);
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);

        return ContentAudioScript::query()
            ->where('episode_id', $episodeId)
            ->whereHas('episode', fn ($query) => $query
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->where('content_type', 'script'));
    }
}
