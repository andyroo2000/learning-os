<?php

namespace App\Http\Requests\Concerns;

use App\Support\DateTime\StrictIsoDateTime;
use Closure;
use Illuminate\Support\Carbon;

trait ValidatesStrictIsoDateTime
{
    protected function parseStrictIsoDateTimeForValidation(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        return StrictIsoDateTime::parseOrNull($value);
    }

    protected function strictIsoDateTimeRule(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_string($value) || StrictIsoDateTime::parseOrNull($value) === null) {
                $fail($message);
            }
        };
    }
}
