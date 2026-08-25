<?php

namespace Tests\Unit\Contracts;

use Tests\Support\Contracts\CompatibilityFixtureRepository;
use Tests\TestCase;

class CompatibilityFixtureManifestTest extends TestCase
{
    public function test_manifest_registers_unique_canonical_fixtures_with_published_checksums(): void
    {
        $manifest = CompatibilityFixtureRepository::manifest();

        $this->assertSame(1, $manifest['schemaVersion']);
        $this->assertSame('andyroo2000/learning-os', $manifest['authority']['repository']);
        $this->assertSame(CompatibilityFixtureRepository::MANIFEST_PATH, $manifest['authority']['manifest']);
        $this->assertFalse($manifest['authority']['productionRuntimeLoadsFixtures']);

        $fixtures = $manifest['fixtures'];
        $this->assertNotEmpty($fixtures);
        $this->assertCount(count($fixtures), array_unique(array_column($fixtures, 'id')));
        $this->assertCount(count($fixtures), array_unique(array_column($fixtures, 'path')));

        foreach ($fixtures as $fixture) {
            $contents = CompatibilityFixtureRepository::fixture($fixture['id']);

            $this->assertSame(1, $contents['schemaVersion'], $fixture['id']);
            $this->assertSame($fixture['id'], $contents['contract']['id'], $fixture['id']);
            $this->assertSame($fixture['producer'], $contents['contract']['producer'], $fixture['id']);
            $this->assertSame(
                $manifest['authority']['repository'],
                $contents['contract']['canonicalRepository'],
                $fixture['id'],
            );
            $this->assertNotEmpty($contents['cases'], $fixture['id']);
            $this->assertCount(
                count($contents['cases']),
                array_unique(array_column($contents['cases'], 'id')),
                $fixture['id'],
            );
            $this->assertSame(
                hash_file('sha256', base_path($fixture['path'])),
                $fixture['sha256'],
                $fixture['id'],
            );

            $checksum = file_get_contents(base_path($fixture['checksumPath']));
            $this->assertNotFalse($checksum, $fixture['id']);
            $this->assertSame(
                "{$fixture['sha256']}  ".basename($fixture['path']),
                trim((string) $checksum),
                $fixture['id'],
            );
        }
    }

    public function test_manifest_bytes_match_the_published_checksum(): void
    {
        $checksum = file_get_contents(base_path(CompatibilityFixtureRepository::MANIFEST_CHECKSUM_PATH));

        $this->assertNotFalse($checksum);
        $this->assertSame(
            hash_file('sha256', base_path(CompatibilityFixtureRepository::MANIFEST_PATH)).'  manifest-v1.json',
            trim((string) $checksum),
        );
    }
}
