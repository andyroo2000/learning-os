<?php

namespace App\Http\Requests\Analytics;

final class StoreBrowserToolAnalyticsEventRequest extends ToolAnalyticsEventRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
