<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabCourseEpisodeSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('CourseEpisode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('courseId');
            $table->string('episodeId');
            $table->integer('order');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('CourseEpisode')->insert([
            'id' => $ids['courseEpisode'], 'courseId' => $ids['course'],
            'episodeId' => $ids['dialogueEpisode'], 'order' => 3,
        ]);
    }
}
