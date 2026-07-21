<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final class PromoteContentEpisodeOwnershipAction
{
    /**
     * The caller must hold the Convo Lab source lock so replacement imports cannot
     * delete an Episode or referenced media halfway through ownership promotion.
     *
     * @param  iterable<int, ContentEpisode>  $episodes
     */
    public function handle(ConnectionInterface $connection, iterable $episodes): void
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Content Episode ownership promotion requires an active transaction.');
        }

        foreach ($episodes as $episode) {
            if ($episode->source_system !== ContentSourceSystem::LEARNING_OS) {
                $episode->source_system = ContentSourceSystem::LEARNING_OS;
                $episode->save();
            }

            ContentAudioScriptMedia::query()
                ->whereHas('segments.script', function ($query) use ($episode): void {
                    $query->where('episode_id', $episode->id);
                })
                ->update(['source_system' => ContentSourceSystem::LEARNING_OS]);
        }
    }
}
