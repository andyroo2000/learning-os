<?php

namespace App\Http\Resources\Admin;

use App\Domain\Admin\Support\LegacyJavaScriptValue;

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
        $hasExchanges = $stage === 'exchanges' || LegacyJavaScriptValue::isTruthy($storedExchanges);
        $hasScript = $stage === 'script' || LegacyJavaScriptValue::isTruthy($storedScriptUnits);

        if ($hasScript) {
            $hasExchanges = true;
            $exchanges = $storedExchanges;
            $scriptUnits = $storedScriptUnits;
        } else {
            $exchanges = $hasExchanges
                ? (LegacyJavaScriptValue::isTruthy($storedExchanges) ? $storedExchanges : $scriptData)
                : null;
            $scriptUnits = null;
        }

        if (LegacyJavaScriptValue::isTruthy($scriptUnitsJson)) {
            $hasScript = true;
            $scriptUnits = $scriptUnitsJson;
        }

        return compact('hasExchanges', 'hasScript', 'exchanges', 'scriptUnits');
    }
}
