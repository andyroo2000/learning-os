<?php

namespace Tests\Support\Study;

trait BuildsWaniKaniApiResponses
{
    private function assignment(
        int $subjectId,
        string $subjectType,
        int $srsStage,
        ?string $passedAt,
    ): array {
        return [
            'object' => 'assignment',
            'data_updated_at' => '2026-07-18T12:00:00.000000Z',
            'data' => [
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'srs_stage' => $srsStage,
                'passed_at' => $passedAt,
                'burned_at' => null,
                'hidden' => false,
            ],
        ];
    }

    private function assignmentCollection(array $assignments, ?int $totalCount = null): array
    {
        return [
            'object' => 'collection',
            'pages' => ['next_url' => null],
            'total_count' => $totalCount ?? count($assignments),
            'data' => $assignments,
        ];
    }

    private function kanjiSubject(int $id, string $character): array
    {
        return ['id' => $id, 'object' => 'kanji', 'data' => ['characters' => $character]];
    }

    private function vocabularySubject(
        int $id,
        string $characters,
        array $readings,
        array $meanings,
    ): array {
        return $this->studySubject($id, [
            'type' => 'vocabulary',
            'characters' => $characters,
            'readings' => $readings,
            'meanings' => $meanings,
        ]);
    }

    /**
     * @param  array{type: string, characters: string, readings: list<string>, meanings: list<string>}  $subject
     */
    protected function studySubject(int $id, array $subject): array
    {
        return [
            'id' => $id,
            'object' => $subject['type'],
            'data_updated_at' => '2026-07-18T12:00:00.000000Z',
            'data' => [
                'characters' => $subject['characters'],
                'readings' => array_map(
                    fn (string $reading): array => ['reading' => $reading, 'accepted_answer' => true],
                    $subject['readings'],
                ),
                'meanings' => array_map(
                    fn (string $meaning): array => ['meaning' => $meaning, 'accepted_answer' => true],
                    $subject['meanings'],
                ),
                'hidden_at' => null,
            ],
        ];
    }

    private function subjectCollection(array $subjects): array
    {
        return ['object' => 'collection', 'pages' => ['next_url' => null], 'data' => $subjects];
    }
}
