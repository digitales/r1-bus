<?php

namespace App\Services;

use App\Models\StopSchedule;
use Carbon\CarbonImmutable;

final readonly class ArrivalsResult
{
    /**
     * @param  list<array{route: string, destination: string, minutes: int}>  $arrivals
     */
    public function __construct(
        public StopSchedule $stop,
        public array $arrivals,
        public CarbonImmutable $fetchedAt,
        public bool $stale,
    ) {}
}
