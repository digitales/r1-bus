<?php

use App\Enums\Slot;

it('defines both windows in London time on weekdays', function () {
    expect(config('bus.timezone'))->toBe('Europe/London')
        ->and(config('bus.weekdays'))->toBe([1, 2, 3, 4, 5])
        ->and(config('bus.windows.morning'))->toBe(['start' => '06:30', 'end' => '09:00'])
        ->and(config('bus.windows.afternoon'))->toBe(['start' => '14:30', 'end' => '15:30'])
        ->and(config('bus.cache_seconds'))->toBe(20)
        ->and(config('bus.stale_minutes'))->toBe(15)
        ->and(config('bus.max_arrivals'))->toBe(10);
});

it('has a slot for each window', function () {
    expect(array_map(fn (Slot $slot) => $slot->value, Slot::cases()))
        ->toBe(array_keys(config('bus.windows')))
        ->and(Slot::Morning->label())->toBe('Morning');
});

it('points the TfL client at the public API by default', function () {
    expect(config('services.tfl.base_url'))->toBe('https://api.tfl.gov.uk');
});
