<?php

namespace App\Http\Requests\Analytics;

use App\Http\Support\ConvoLabProxyAuthorization;

final class StoreToolAnalyticsEventRequest extends ToolAnalyticsEventRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'tools:analytics');
    }
}
