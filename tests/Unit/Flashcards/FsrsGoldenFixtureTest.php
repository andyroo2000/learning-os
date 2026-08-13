<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Support\FsrsReviewScheduler;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Support\DateTime\StrictIsoDateTime;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FsrsGoldenFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__.'/../../Fixtures/fsrs-golden-v1.json';

    private const FIXTURE_SHA256_PATH = __DIR__.'/../../Fixtures/fsrs-golden-v1.sha256';

    /** @var list<string> */
    private const SCHEDULER_STATE_FIELDS = [
        'due',
        'stability',
        'difficulty',
        'elapsed_days',
        'scheduled_days',
        'learning_steps',
        'reps',
        'lapses',
        'state',
        'last_review',
    ];

    public function test_fixture_metadata_and_profile_match_the_runtime_contract(): void
    {
        $fixture = self::fixture();

        $this->assertSame(1, $fixture['schema_version']);
        $this->assertSame('convolab-fsrs-golden', $fixture['contract']['name']);
        $this->assertSame(
            'tests/Fixtures/fsrs-golden-v1.json',
            $fixture['contract']['canonical_fixture'],
        );
        $this->assertSame(self::SCHEDULER_STATE_FIELDS, $fixture['contract']['scheduler_state_fields']);
        $this->assertSame(FsrsReviewScheduler::PROFILE, $fixture['profile']);
        $this->assertSame(0.00000001, $fixture['comparison']['float_absolute_tolerance']);
        $this->assertSame('andyroo2000/learning-os', $fixture['provenance']['canonical_repository']);
        $this->assertSame('ts-fsrs', $fixture['provenance']['reference_library']);
        $this->assertSame('5.3.3', $fixture['provenance']['reference_library_version']);
        $this->assertSame(
            'sha512-DgWFirmZe9MTqJiwtlLZoa/xyzibfKtQvuXZoH5X0kei64Cp4o1SlT6EbajjDT8dr/a4x82fLkKrCbePq4bxag==',
            $fixture['provenance']['reference_library_npm_integrity'],
        );

        $schedulingCases = $fixture['scheduling_cases'];
        $transportCases = $fixture['transport_normalization_cases'];
        $this->assertCount(13, $schedulingCases);
        $this->assertCount(8, $transportCases);
        $this->assertCount(13, array_unique(array_column($schedulingCases, 'id')));
        $this->assertCount(8, array_unique(array_column($transportCases, 'id')));
        $this->assertSame(
            ['ts-fsrs-5.3.3', 'cross-runtime-normalization'],
            array_values(array_unique(array_column($schedulingCases, 'source'))),
        );

        foreach ($schedulingCases as $case) {
            $this->assertSame(
                self::SCHEDULER_STATE_FIELDS,
                array_keys($case['expected']['scheduler_state']),
                $case['id'],
            );
        }
    }

    public function test_fixture_bytes_match_the_published_sha256(): void
    {
        $checksum = file_get_contents(self::FIXTURE_SHA256_PATH);
        if ($checksum === false) {
            self::fail('Unable to read the canonical FSRS fixture checksum.');
        }

        $this->assertSame(
            trim($checksum),
            hash_file('sha256', self::FIXTURE_PATH).'  fsrs-golden-v1.json',
        );
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('schedulingCases')]
    public function test_scheduler_matches_the_canonical_golden_vectors(array $case): void
    {
        $input = $case['input'];
        $expected = $case['expected'];
        $result = FsrsReviewScheduler::review(
            schedulerState: $input['scheduler_state'],
            studyStatus: CardStudyStatus::from($input['study_status']),
            rating: CardReviewRating::from($input['rating']),
            reviewedAt: Carbon::parse($input['reviewed_at']),
        );

        $this->assertSame($expected['study_status'], $result['studyStatus']->value, $case['id']);
        $this->assertSame(
            self::timestamp($expected['due_at']),
            self::timestamp($result['dueAt']->toJSON()),
            $case['id'].' due_at',
        );
        $this->assertSame(self::SCHEDULER_STATE_FIELDS, array_keys($result['schedulerState']));

        $tolerance = self::fixture()['comparison']['float_absolute_tolerance'];
        foreach ($expected['scheduler_state'] as $field => $expectedValue) {
            $actualValue = $result['schedulerState'][$field];

            if (in_array($field, ['due', 'last_review'], true)) {
                $this->assertSame(
                    self::nullableTimestamp($expectedValue),
                    self::nullableTimestamp($actualValue),
                    $case['id'].' '.$field,
                );

                continue;
            }

            if (in_array($field, ['stability', 'difficulty'], true)) {
                $this->assertEqualsWithDelta($expectedValue, $actualValue, $tolerance, $case['id'].' '.$field);

                continue;
            }

            $this->assertSame($expectedValue, $actualValue, $case['id'].' '.$field);
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('transportNormalizationCases')]
    public function test_review_timestamp_transport_normalization_matches_the_fixture(array $case): void
    {
        $parsed = StrictIsoDateTime::parseMillisecondsOrNull($case['input']['timestamp']);
        $expected = $case['expected'];

        $this->assertSame($expected['accepted'], $parsed !== null, $case['id']);
        $this->assertSame(
            $expected['canonical_utc'],
            $parsed === null ? null : self::canonicalTimestamp($parsed),
            $case['id'],
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function schedulingCases(): iterable
    {
        foreach (self::fixture()['scheduling_cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function transportNormalizationCases(): iterable
    {
        foreach (self::fixture()['transport_normalization_cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        $contents = file_get_contents(self::FIXTURE_PATH);
        if ($contents === false) {
            self::fail('Unable to read the canonical FSRS fixture.');
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function canonicalTimestamp(Carbon $timestamp): string
    {
        return $timestamp->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function timestamp(string $timestamp): string
    {
        return self::canonicalTimestamp(Carbon::parse($timestamp));
    }

    private static function nullableTimestamp(mixed $timestamp): ?string
    {
        return is_string($timestamp) ? self::timestamp($timestamp) : null;
    }
}
