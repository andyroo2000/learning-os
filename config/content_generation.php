<?php

$monthlyLimit = (int) env('MONTHLY_GENERATION_LIMIT', 30);
$cooldownSeconds = (int) env('CONTENT_GENERATION_COOLDOWN_SECONDS', 30);

// Policy controls are positive-only; invalid or non-positive values fall back safely.
return [
    'monthly_limit' => $monthlyLimit > 0 ? $monthlyLimit : 30,
    'cooldown_seconds' => $cooldownSeconds > 0 ? $cooldownSeconds : 30,
];
