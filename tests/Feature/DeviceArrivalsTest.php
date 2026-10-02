<?php

use App\Enums\Slot;
use App\Models\StopSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function saveStop(Slot $slot, string $naptanId, string $name): StopSchedule
{
    return StopSchedule::create([
        'slot' => $slot,
        'naptan_id' => $naptanId,
        'name' => $name,
        'stop_letter' => 'F',
        'towards' => 'Holborn',
    ]);
}

function travelToLondon(string $time): void
{
    test()->travelTo(CarbonImmutable::parse($time, 'Europe/London'));
}

it('returns 404 for a wrong token', function () {
    $this->getJson('/r1/not-the-token/arrivals')->assertNotFound();
});

it('returns live arrivals for the morning stop inside the morning window', function () {
    travelToLondon('2026-10-05 07:41:10');
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    saveStop(Slot::Afternoon, '490000129E', 'Kings Cross Station');
    Http::fake(['api.tfl.gov.uk/StopPoint/490000007F/Arrivals*' => Http::response([
        tflPrediction('73', 'Stoke Newington', now()->addMinutes(6)),
        tflPrediction('19', 'Battersea Bridge', now()->addMinutes(3)),
    ])]);

    $this->getJson(deviceUrl('/arrivals'))
        ->assertOk()
        ->assertExactJson([
            'state' => 'live',
            'stop' => ['name' => 'Angel Station', 'letter' => 'F', 'towards' => 'Holborn'],
            'arrivals' => [
                ['route' => '19', 'destination' => 'Battersea Bridge', 'minutes' => 3],
                ['route' => '73', 'destination' => 'Stoke Newington', 'minutes' => 6],
            ],
            'fetched_at' => '2026-10-05T07:41:10+01:00',
            'fetched_label' => '07:41',
            'stale' => false,
            'stale_minutes' => 0,
            'next_window' => null,
            'next_window_label' => null,
        ]);
});

it('uses the afternoon stop inside the afternoon window', function () {
    travelToLondon('2026-10-05 15:00:00');
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    saveStop(Slot::Afternoon, '490000129E', 'Kings Cross Station');
    Http::fake(['api.tfl.gov.uk/StopPoint/490000129E/Arrivals*' => Http::response([])]);

    $this->getJson(deviceUrl('/arrivals'))
        ->assertOk()
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('stop.name', 'Kings Cross Station')
        ->assertJsonPath('arrivals', []);
});

it('does not call TfL outside a window and says when the next one starts', function () {
    travelToLondon('2026-10-05 10:00:00');
    saveStop(Slot::Afternoon, '490000129E', 'Kings Cross Station');
    Http::fake();

    $this->getJson(deviceUrl('/arrivals'))
        ->assertOk()
        ->assertExactJson([
            'state' => 'outside_window',
            'stop' => null,
            'arrivals' => [],
            'fetched_at' => null,
            'fetched_label' => null,
            'stale' => false,
            'stale_minutes' => 0,
            'next_window' => '2026-10-05T14:30:00+01:00',
            'next_window_label' => 'Next check 14:30',
        ]);

    Http::assertNothingSent();
});

it('names the day when the next window is not today', function () {
    travelToLondon('2026-10-02 16:00:00');

    $this->getJson(deviceUrl('/arrivals'))
        ->assertJsonPath('state', 'outside_window')
        ->assertJsonPath('next_window_label', 'Back Monday 06:30');
});

it('formats labels in London time even when the app runs in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
    travelToLondon('2026-10-05 08:59:00');
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    Http::fake(['api.tfl.gov.uk/*' => Http::response([])]);

    $this->getJson(deviceUrl('/arrivals'))->assertJsonPath('fetched_label', '08:59');
});

it('checks the upcoming slot once when asked outside a window', function (string $time, string $expectedStop) {
    travelToLondon($time);
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    saveStop(Slot::Afternoon, '490000129E', 'Kings Cross Station');
    Http::fake(['api.tfl.gov.uk/*' => Http::response([
        tflPrediction('73', 'Stoke Newington', now()->addMinutes(6)),
    ])]);

    $this->getJson(deviceUrl('/arrivals?check=1'))
        ->assertOk()
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('stop.name', $expectedStop)
        ->assertJsonPath('arrivals.0.route', '73');
})->with([
    'mid morning uses the afternoon stop' => ['2026-10-05 10:00:00', 'Kings Cross Station'],
    'evening uses the morning stop' => ['2026-10-05 16:00:00', 'Angel Station'],
    'weekend uses the morning stop' => ['2026-10-03 12:00:00', 'Angel Station'],
]);

it('reports no_stop when the slot has no stop', function () {
    travelToLondon('2026-10-05 07:00:00');
    Http::fake();

    $this->getJson(deviceUrl('/arrivals'))
        ->assertOk()
        ->assertJsonPath('state', 'no_stop')
        ->assertJsonPath('stop', null);
});

it('reports unavailable when TfL fails with nothing cached', function () {
    travelToLondon('2026-10-05 07:00:00');
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    Http::fake(['api.tfl.gov.uk/*' => Http::response('Bad gateway', 502)]);

    $this->getJson(deviceUrl('/arrivals'))
        ->assertOk()
        ->assertJsonPath('state', 'unavailable')
        ->assertJsonPath('arrivals', []);
});

it('marks a fallback result as stale with its age', function () {
    travelToLondon('2026-10-05 07:00:00');
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    Http::fakeSequence('api.tfl.gov.uk/*')
        ->push([tflPrediction('73', 'Stoke Newington', now()->addMinutes(9))])
        ->whenEmpty(Http::response('Bad gateway', 502));

    $this->getJson(deviceUrl('/arrivals'))->assertJsonPath('stale', false);
    $this->travel(2)->minutes();

    $this->getJson(deviceUrl('/arrivals'))
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('stale', true)
        ->assertJsonPath('stale_minutes', 2)
        ->assertJsonPath('fetched_label', '07:00')
        ->assertJsonPath('arrivals.0.minutes', 7);
});

it('rate limits the device endpoint to 30 requests a minute', function () {
    travelToLondon('2026-10-05 10:00:00');
    $url = deviceUrl('/arrivals');

    foreach (range(1, 30) as $attempt) {
        $this->getJson($url)->assertOk();
    }

    $this->getJson($url)->assertStatus(429);
});
