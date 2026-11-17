<?php

// Public standard API prices, USD per million units. Estimates, not invoices.
// https://developers.openai.com/api/docs/pricing
// https://docs.fish.audio/developer-guide/models-pricing/pricing-and-rate-limits
return [
    'fish_launch_ends_at' => '2026-12-01 08:00:00',
    'openai' => [
        'gpt-6.1-sol' => ['input' => 2, 'cached' => 0.10, 'output' => 10],
        'gpt-5.5' => ['input' => 5, 'cached' => 0.50, 'output' => 30],
    ],
    'fish' => [
        'drama-3' => ['launch' => 10, 'standard' => 50],
        's2.1-pro' => ['launch' => 15, 'standard' => 15],
        's2-pro' => ['launch' => 15, 'standard' => 15],
    ],
];
