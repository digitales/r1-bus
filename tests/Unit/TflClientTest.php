<?php

use App\Exceptions\TflUnavailable;
use App\Services\TflClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function busStopNode(string $id, string $name, ?string $letter, ?string $towards, array $children = []): array
{
    return [
        'naptanId' => $id,
        'commonName' => $name,
        'stopType' => 'NaptanPublicBusCoachTram',
        'stopLetter' => $letter,
        'additionalProperties' => $towards === null ? [] : [
            ['key' => 'CompassPoint', 'value' => 'S'],
            ['key' => 'Towards', 'value' => $towards],
        ],
        'children' => $children,
    ];
}

it('searches bus stops by name', function () {
    Http::fake(['api.tfl.gov.uk/StopPoint/Search/*' => Http::response(['matches' => [
        ['id' => 'HUBVIC', 'name' => 'Victoria'],
        ['id' => '490000248G', 'name' => 'Victoria Station', 'towards' => 'Battersea'],
    ]])]);

    expect((new TflClient)->searchStops(' victoria '))->toBe([
        ['id' => 'HUBVIC', 'name' => 'Victoria', 'towards' => null],
        ['id' => '490000248G', 'name' => 'Victoria Station', 'towards' => 'Battersea'],
    ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/StopPoint/Search/victoria?')
        && $request['modes'] === 'bus');
});

it('returns nothing for a blank search without calling TfL', function () {
    Http::fake();

    expect((new TflClient)->searchStops('   '))->toBe([]);

    Http::assertNothingSent();
});

it('encodes slashes and apostrophes in the search term', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response(['matches' => []])]);

    (new TflClient)->searchStops("King's Cross / Pentonville");

    Http::assertSent(fn (Request $request) => str_contains(
        $request->url(),
        '/StopPoint/Search/King%27s%20Cross%20%2F%20Pentonville?'
    ));
});

it('sends the app key when one is configured', function () {
    config(['services.tfl.app_key' => 'secret-key']);
    Http::fake(['api.tfl.gov.uk/*' => Http::response([])]);

    (new TflClient)->arrivals('490000007F');

    Http::assertSent(fn (Request $request) => $request['app_key'] === 'secret-key');
});

it('lists the individual bus stops inside a hub', function () {
    Http::fake(['api.tfl.gov.uk/StopPoint/HUBVIC*' => Http::response([
        'naptanId' => 'HUBVIC',
        'commonName' => 'Victoria',
        'stopType' => 'TransportInterchange',
        'children' => [
            [
                'naptanId' => '490G00014050',
                'commonName' => 'Victoria Bus Station',
                'stopType' => 'NaptanOnstreetBusCoachStopCluster',
                'children' => [
                    busStopNode('490014050A', 'Victoria Bus Station', 'A', 'Green Park'),
                    busStopNode('490014050B', 'Victoria Bus Station', 'B', null),
                ],
            ],
            ['naptanId' => '940GZZLUVIC', 'commonName' => 'Victoria Underground', 'stopType' => 'NaptanMetroStation'],
        ],
    ])]);

    expect((new TflClient)->stopsAt('HUBVIC'))->toBe([
        ['naptan_id' => '490014050A', 'name' => 'Victoria Bus Station', 'stop_letter' => 'A', 'towards' => 'Green Park'],
        ['naptan_id' => '490014050B', 'name' => 'Victoria Bus Station', 'stop_letter' => 'B', 'towards' => null],
    ]);
});

it('returns the stop itself when the id is already an individual stop', function () {
    Http::fake(['api.tfl.gov.uk/StopPoint/490000007F*' => Http::response(
        busStopNode('490000007F', 'Angel Station', 'F', 'Holborn')
    )]);

    expect((new TflClient)->stopsAt('490000007F'))->toBe([
        ['naptan_id' => '490000007F', 'name' => 'Angel Station', 'stop_letter' => 'F', 'towards' => 'Holborn'],
    ]);
});

it('returns raw arrivals for a stop', function () {
    $prediction = tflPrediction('73', 'Stoke Newington', now()->addMinutes(3));
    Http::fake(['api.tfl.gov.uk/StopPoint/490000007F/Arrivals*' => Http::response([$prediction])]);

    expect((new TflClient)->arrivals('490000007F'))->toBe([$prediction]);
});

it('tries twice then reports TfL unavailable on server errors', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response('Bad gateway', 502)]);

    expect(fn () => (new TflClient)->arrivals('490000007F'))->toThrow(TflUnavailable::class);

    Http::assertSentCount(2);
});

it('reports TfL unavailable when the connection fails', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::failedConnection()]);

    expect(fn () => (new TflClient)->arrivals('490000007F'))->toThrow(TflUnavailable::class);
});

it('reports TfL unavailable when a 200 response is not JSON', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response('<html>Maintenance</html>', 200)]);

    expect(fn () => (new TflClient)->arrivals('490000007F'))->toThrow(TflUnavailable::class);
});
