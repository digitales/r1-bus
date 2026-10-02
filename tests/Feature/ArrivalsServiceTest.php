<?php

use App\Enums\Slot;
use App\Exceptions\TflUnavailable;
use App\Models\StopSchedule;
use App\Services\ArrivalsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'Europe/London'));

    StopSchedule::create([
        'slot' => Slot::Morning,
        'naptan_id' => '490000007F',
        'name' => 'Angel Station',
        'stop_letter' => 'F',
        'towards' => 'Holborn',
    ]);
});

it('returns null when the slot has no stop', function () {
    Http::fake();

    expect(app(ArrivalsService::class)->forSlot(Slot::Afternoon))->toBeNull();

    Http::assertNothingSent();
});

it('maps arrivals soonest first with whole minutes', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response([
        tflPrediction('73', 'Stoke Newington', now()->addSeconds(400)),
        tflPrediction('19', 'Battersea Bridge', now()->addSeconds(45)),
        tflPrediction('38', 'Victoria', now()->addSeconds(179)),
    ])]);

    $result = app(ArrivalsService::class)->forSlot(Slot::Morning);

    expect($result->arrivals)->toBe([
        ['route' => '19', 'destination' => 'Battersea Bridge', 'minutes' => 0],
        ['route' => '38', 'destination' => 'Victoria', 'minutes' => 2],
        ['route' => '73', 'destination' => 'Stoke Newington', 'minutes' => 6],
    ])
        ->and($result->stale)->toBeFalse()
        ->and($result->stop->name)->toBe('Angel Station')
        ->and($result->fetchedAt->equalTo(now()))->toBeTrue();
});

it('keeps at most the configured number of arrivals', function () {
    $predictions = [];
    foreach (range(1, 14) as $minute) {
        $predictions[] = tflPrediction((string) $minute, 'Somewhere', now()->addMinutes($minute));
    }
    Http::fake(['api.tfl.gov.uk/*' => Http::response($predictions)]);

    expect(app(ArrivalsService::class)->forSlot(Slot::Morning)->arrivals)->toHaveCount(10);
});

it('skips predictions missing the fields it needs', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response([
        ['lineName' => '73'],
        'not-an-array',
        tflPrediction('19', 'Battersea Bridge', now()->addMinutes(4)),
    ])]);

    expect(app(ArrivalsService::class)->forSlot(Slot::Morning)->arrivals)->toBe([
        ['route' => '19', 'destination' => 'Battersea Bridge', 'minutes' => 4],
    ]);
});

it('calls TfL once within 20 seconds and again after', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response([])]);
    $service = app(ArrivalsService::class);

    $service->forSlot(Slot::Morning);
    $this->travel(19)->seconds();
    $service->forSlot(Slot::Morning);
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    $service->forSlot(Slot::Morning);
    Http::assertSentCount(2);
});

it('falls back to the last good result, recomputing minutes and dropping departed buses', function () {
    Http::fakeSequence('api.tfl.gov.uk/*')
        ->push([
            tflPrediction('19', 'Battersea Bridge', now()->addMinutes(2)),
            tflPrediction('73', 'Stoke Newington', now()->addMinutes(9)),
        ])
        ->whenEmpty(Http::response('Bad gateway', 502));
    $service = app(ArrivalsService::class);
    $firstFetch = now();

    $service->forSlot(Slot::Morning);
    $this->travel(5)->minutes();
    $result = $service->forSlot(Slot::Morning);

    expect($result->stale)->toBeTrue()
        ->and($result->arrivals)->toBe([
            ['route' => '73', 'destination' => 'Stoke Newington', 'minutes' => 4],
        ])
        ->and($result->fetchedAt->equalTo($firstFetch))->toBeTrue();
});

it('throws when TfL fails and the last good result is over 15 minutes old', function () {
    Http::fakeSequence('api.tfl.gov.uk/*')
        ->push([tflPrediction('73', 'Stoke Newington', now()->addMinutes(30))])
        ->whenEmpty(Http::response('Bad gateway', 502));
    $service = app(ArrivalsService::class);

    $service->forSlot(Slot::Morning);
    $this->travel(16)->minutes();

    expect(fn () => $service->forSlot(Slot::Morning))->toThrow(TflUnavailable::class);
});

it('throws when TfL fails and nothing was ever cached', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response('Bad gateway', 502)]);

    expect(fn () => app(ArrivalsService::class)->forSlot(Slot::Morning))->toThrow(TflUnavailable::class);
});
