<?php

namespace App\Domain\Analytics\Contracts;

interface ToolAnalyticsLogger
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function write(array $event): void;
}
