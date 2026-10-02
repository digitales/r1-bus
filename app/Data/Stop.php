<?php

namespace App\Data;

use App\Enums\Direction;
use App\Enums\Slot;

final readonly class Stop
{
    public function __construct(
        public Slot $slot,
        public Direction $direction,
        public string $naptanId,
        public string $name,
        public ?string $stopLetter = null,
        public ?string $towards = null,
    ) {}
}
