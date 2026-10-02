<?php

use App\Enums\Slot;
use App\Services\WindowResolver;
use Carbon\CarbonImmutable;

function london(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/London');
}

it('resolves the current slot', function (string $time, ?Slot $expected) {
    expect((new WindowResolver)->current(london($time)))->toBe($expected);
})->with([
    'before morning' => ['2026-10-05 06:29:59', null],
    'morning start is inclusive' => ['2026-10-05 06:30:00', Slot::Morning],
    'last morning minute' => ['2026-10-05 08:59:59', Slot::Morning],
    'morning end is exclusive' => ['2026-10-05 09:00:00', null],
    'midday' => ['2026-10-05 12:00:00', null],
    'afternoon start' => ['2026-10-05 14:30:00', Slot::Afternoon],
    'last afternoon minute' => ['2026-10-05 15:29:59', Slot::Afternoon],
    'afternoon end is exclusive' => ['2026-10-05 15:30:00', null],
    'saturday morning' => ['2026-10-03 07:00:00', null],
    'sunday afternoon' => ['2026-10-04 15:00:00', null],
]);

it('reads the window in London time whatever timezone it is given', function (string $utc, ?Slot $expected) {
    expect((new WindowResolver)->current(CarbonImmutable::parse($utc, 'UTC')))->toBe($expected);
})->with([
    'BST: 05:30 UTC is 06:30 London' => ['2026-10-23 05:30:00', Slot::Morning],
    'GMT after clocks go back: 05:30 UTC is 05:30 London' => ['2026-10-26 05:30:00', null],
    'GMT after clocks go back: 06:30 UTC is 06:30 London' => ['2026-10-26 06:30:00', Slot::Morning],
    'GMT before clocks go forward' => ['2026-03-27 06:30:00', Slot::Morning],
    'BST after clocks go forward' => ['2026-03-30 05:30:00', Slot::Morning],
]);

it('finds the upcoming slot and when it starts', function (string $time, Slot $slot, string $start) {
    $resolver = new WindowResolver;

    expect($resolver->upcoming(london($time)))->toBe($slot)
        ->and($resolver->nextStart(london($time))->format('Y-m-d H:i T'))->toBe($start);
})->with([
    'early monday' => ['2026-10-05 05:00:00', Slot::Morning, '2026-10-05 06:30 BST'],
    'between windows' => ['2026-10-05 10:00:00', Slot::Afternoon, '2026-10-05 14:30 BST'],
    'after the afternoon window' => ['2026-10-05 16:00:00', Slot::Morning, '2026-10-06 06:30 BST'],
    'friday evening rolls to monday' => ['2026-10-02 16:00:00', Slot::Morning, '2026-10-05 06:30 BST'],
    'saturday' => ['2026-10-03 12:00:00', Slot::Morning, '2026-10-05 06:30 BST'],
    'across the clock change' => ['2026-10-23 16:00:00', Slot::Morning, '2026-10-26 06:30 GMT'],
]);
