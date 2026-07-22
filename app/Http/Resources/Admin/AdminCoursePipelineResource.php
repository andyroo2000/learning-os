<?php

namespace App\Http\Resources\Admin;

use App\Domain\Admin\Support\LegacyJavaScriptValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminCoursePipelineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $scriptJson = $this->resource->script_json;
        $scriptUnitsJson = $this->resource->script_units_json;
        $stage = null;
        $exchanges = null;
        $scriptUnits = null;

        if (is_array($scriptJson)) {
            if (($scriptJson['_pipelineStage'] ?? null) === 'exchanges') {
                $stage = 'exchanges';
                $exchanges = LegacyJavaScriptValue::isTruthy($scriptJson['_exchanges'] ?? null)
                    ? $scriptJson['_exchanges']
                    : null;
            } elseif (($scriptJson['_pipelineStage'] ?? null) === 'script') {
                $stage = 'script';
                $exchanges = LegacyJavaScriptValue::isTruthy($scriptJson['_exchanges'] ?? null)
                    ? $scriptJson['_exchanges']
                    : null;
                $scriptUnits = LegacyJavaScriptValue::isTruthy($scriptJson['_scriptUnits'] ?? null)
                    ? $scriptJson['_scriptUnits']
                    : null;
            } elseif (array_is_list($scriptJson)) {
                $stage = 'script';
                $scriptUnits = $scriptJson;
            }
        }

        if (! LegacyJavaScriptValue::isTruthy($scriptUnits)
            && is_array($scriptUnitsJson)
            && array_is_list($scriptUnitsJson)) {
            $stage ??= 'script';
            $scriptUnits = $scriptUnitsJson;
        }

        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status,
            'stage' => $stage,
            'exchanges' => $exchanges,
            'scriptUnits' => $scriptUnits,
            'audioUrl' => $this->resource->audio_url,
            'approxDurationSeconds' => $this->resource->approx_duration_seconds,
        ];
    }
}
