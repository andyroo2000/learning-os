<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabCourseCoreItemSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('CourseCoreItem', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('courseId');
            $table->text('textL2');
            $table->text('readingL2')->nullable();
            $table->text('translationL1');
            $table->double('complexityScore');
            $table->string('sourceEpisodeId')->nullable();
            $table->string('sourceSentenceId')->nullable();
            $table->integer('sourceUnitIndex')->nullable();
            $table->json('components')->nullable();
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('CourseCoreItem')->insert([
            'id' => $ids['coreItem'], 'courseId' => $ids['course'], 'textL2' => '猫',
            'readingL2' => 'ねこ', 'translationL1' => 'cat', 'complexityScore' => 1.25,
            'sourceEpisodeId' => $ids['dialogueEpisode'], 'sourceSentenceId' => $ids['sentence'],
            'sourceUnitIndex' => 2, 'components' => json_encode([['text' => '猫']]),
        ]);
    }
}
