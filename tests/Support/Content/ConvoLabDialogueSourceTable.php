<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabDialogueSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('Dialogue', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('Dialogue')->insert([
            'id' => $ids['dialogue'],
            'episodeId' => $ids['dialogueEpisode'],
            'createdAt' => $created,
            'updatedAt' => $created,
        ]);
    }
}
