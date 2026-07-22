<?php

namespace Tests\Unit\Admin;

use App\Http\Resources\Admin\AdminScriptLabCourseState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminScriptLabCourseStateTest extends TestCase
{
    #[DataProvider('stateProvider')]
    public function test_state_matches_legacy_javascript_json_semantics(
        mixed $scriptJson,
        mixed $scriptUnitsJson,
        array $expected,
    ): void {
        $this->assertSame($expected, AdminScriptLabCourseState::from($scriptJson, $scriptUnitsJson));
    }

    /** @return iterable<string, array{mixed, mixed, array<string, mixed>}> */
    public static function stateProvider(): iterable
    {
        yield 'null state' => [null, null, self::state(false, false)];
        yield 'scalar script JSON is ignored' => ['legacy', null, self::state(false, false)];
        yield 'empty exchanges array is truthy in JavaScript' => [
            ['_exchanges' => []],
            null,
            self::state(true, false, [], null),
        ];
        yield 'false metadata stays absent' => [
            ['_exchanges' => false, '_scriptUnits' => false],
            false,
            self::state(false, false),
        ];
        yield 'script stage implies exchanges even without payloads' => [
            ['_pipelineStage' => 'script'],
            null,
            self::state(true, true, null, null),
        ];
        yield 'canonical empty script units override embedded units' => [
            ['_scriptUnits' => [['type' => 'L2', 'text' => 'old']]],
            [],
            self::state(true, true, null, []),
        ];
        yield 'scalar canonical units retain legacy truthiness' => [
            null,
            'legacy-units',
            self::state(false, true, null, 'legacy-units'),
        ];
    }

    /** @return array{hasExchanges: bool, hasScript: bool, exchanges: mixed, scriptUnits: mixed} */
    private static function state(
        bool $hasExchanges,
        bool $hasScript,
        mixed $exchanges = null,
        mixed $scriptUnits = null,
    ): array {
        return compact('hasExchanges', 'hasScript', 'exchanges', 'scriptUnits');
    }
}
