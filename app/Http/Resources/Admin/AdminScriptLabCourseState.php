<?php

namespace App\Http\Resources\Admin;

final class AdminScriptLabCourseState
{
    /**
     * @return array{hasExchanges: bool, hasScript: bool, exchanges: mixed, scriptUnits: mixed}
     */
    public static function from(mixed $scriptJson, mixed $scriptUnitsJson): array
    {
        $scriptData = is_array($scriptJson) && ! array_is_list($scriptJson)
            ? $scriptJson
            : null;
        $stage = $scriptData['_pipelineStage'] ?? null;
        $storedExchanges = $scriptData['_exchanges'] ?? null;
        $storedScriptUnits = $scriptData['_scriptUnits'] ?? null;
        $hasExchanges = $stage === 'exchanges' || self::legacyTruthy($storedExchanges);
        $hasScript = $stage === 'script' || self::legacyTruthy($storedScriptUnits);

        if ($hasScript) {
            $hasExchanges = true;
            $exchanges = $storedExchanges;
            $scriptUnits = $storedScriptUnits;
        } else {
            $exchanges = $hasExchanges
                ? (self::legacyTruthy($storedExchanges) ? $storedExchanges : $scriptData)
                : null;
            $scriptUnits = null;
        }

        if (self::legacyTruthy($scriptUnitsJson)) {
            $hasScript = true;
            $scriptUnits = $scriptUnitsJson;
        }

        return compact('hasExchanges', 'hasScript', 'exchanges', 'scriptUnits');
    }

    private static function legacyTruthy(mixed $value): bool
    {
        return is_array($value) || is_object($value) || (bool) $value;
    }
}
