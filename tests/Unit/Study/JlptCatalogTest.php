<?php

namespace Tests\Unit\Study;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JlptCatalogTest extends TestCase
{
    #[DataProvider('catalogProvider')]
    public function test_versioned_catalog_has_expected_checksum_count_and_unique_ids(
        string $filename,
        string $checksum,
        int $expectedRows,
        string $expectedLevel,
    ): void {
        $path = LEARNING_OS_PROJECT_ROOT.'/resources/jlpt/v1/'.$filename;

        $this->assertFileExists($path);
        $this->assertSame($checksum, hash_file('sha256', $path));

        $handle = fopen($path, 'rb');
        $this->assertIsResource($handle);
        $header = fgetcsv($handle, escape: '');
        $this->assertIsArray($header);
        $ids = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $this->assertCount(count($header), $values);
            $row = array_combine($header, $values);
            $this->assertIsArray($row);
            $ids[] = $row['concept_id'];
            $this->assertSame($expectedLevel, $row['jlpt_level']);
        }

        fclose($handle);

        $this->assertCount($expectedRows, $ids);
        $this->assertCount($expectedRows, array_unique($ids));
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function catalogProvider(): array
    {
        return [
            'N5 vocabulary' => ['n5-vocabulary.csv', 'cf33a3040e31192658ae4c4b6a43485711837cc0be79004637956fac460e3ee6', 684, 'N5'],
            'N5 grammar' => ['n5-grammar.csv', '9c0cb4e336928a014a8487df009fc796dd9ab5e294fc99b44da9a0ceb8b44a4c', 77, 'N5'],
            'N4 vocabulary' => ['n4-vocabulary.csv', '3ef6b09a483ccc61fa3f2ed96552e7574b2cbba67dbd442226599947b5f64c73', 640, 'N4'],
            'N4 grammar' => ['n4-grammar.csv', 'b736cde06c9d8d744d405340e904764c1d86cd385c98ca4a3e3e3a099ca540dd', 89, 'N4'],
            'N3 vocabulary' => ['n3-vocabulary.csv', '5a4d967b9051fc914da4404694bc04cb786ab3cfc1919a8fcbdd308dd9d583be', 1730, 'N3'],
            'N3 grammar' => ['n3-grammar.csv', '53bbe15a89992512cd25e8247920fa3dca1add0ac742baf9bc5b8cb949b99c66', 130, 'N3'],
            'N2 vocabulary' => ['n2-vocabulary.csv', '64b1e947cab90fe13d0be8bc9062ed689995b2a29d150324b5b5714716260c83', 1812, 'N2'],
            'N2 grammar' => ['n2-grammar.csv', '015f631a8ae5ee407bac66617aea51fc5cf33f25d7185b9ac4245b6d5f85dc22', 149, 'N2'],
            'N1 vocabulary' => ['n1-vocabulary.csv', '899a790e196d3829a374b74f85e1482ee7950b883143eaf53e29f5fd5256c66e', 3423, 'N1'],
            'N1 grammar' => ['n1-grammar.csv', '3732ed6862309dd3e50d64c53188521ab718f57c8f131606dfae5c74460d70bd', 150, 'N1'],
        ];
    }
}
