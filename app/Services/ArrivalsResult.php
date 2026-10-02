<?php

namespace App\Services;

use App\Data\Stop;
use Carbon\CarbonImmutable;

final readonly class ArrivalsResult
{
    /**
     * @param  list<array{route: string, destination: string, minutes: int}>  $arrivals
     */
    public function __construct(
        public Stop $stop,
        public array $arrivals,
        public CarbonImmutable $fetchedAt,
        public bool $stale,
    ) {}
}
