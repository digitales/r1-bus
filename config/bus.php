<?php

return [

    'timezone' => 'Europe/London',

    // ISO-8601 day numbers: 1 is Monday, 7 is Sunday.
    'weekdays' => [1, 2, 3, 4, 5],

    // Keys must match App\Enums\Slot values, in chronological order.
    // Start is inclusive, end is exclusive.
    'windows' => [
        'morning' => ['start' => '06:30', 'end' => '09:00'],
        'afternoon' => ['start' => '14:30', 'end' => '15:30'],
    ],

    'cache_seconds' => 20,

    'stale_minutes' => 15,

    'max_arrivals' => 10,

];
