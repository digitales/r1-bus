<?php

use App\Data\Stop;
use App\Enums\Direction;
use App\Enums\Slot;
use App\Livewire\StopScheduleEditor;
use App\Services\BusStore;
use App\Services\DeviceToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function tflStop(string $id, string $name, ?string $letter, ?string $towards, array $children = []): array
{
    return [
        'naptanId' => $id,
        'commonName' => $name,
        'stopType' => 'NaptanPublicBusCoachTram',
        'stopLetter' => $letter,
        'additionalProperties' => $towards === null ? [] : [['key' => 'Towards', 'value' => $towards]],
        'children' => $children,
    ];
}

function fakeVictoria(): void
{
    Http::fake([
        'api.tfl.gov.uk/StopPoint/Search/*' => Http::response(['matches' => [
            ['id' => 'HUBVIC', 'name' => 'Victoria'],
            ['id' => '490000248G', 'name' => 'Victoria Station', 'towards' => 'Battersea'],
        ]]),
        'api.tfl.gov.uk/StopPoint/HUBVIC,490000248G*' => Http::response([
            [
                'naptanId' => 'HUBVIC',
                'commonName' => 'Victoria',
                'stopType' => 'TransportInterchange',
                'children' => [
                    tflStop('490014050A', 'Victoria Bus Station', 'A', 'Green Park'),
                    tflStop('490014050B', 'Victoria Bus Station', 'B', 'Pimlico'),
                ],
            ],
            tflStop('490000248G', 'Victoria Station', 'G', 'Battersea'),
        ]),
    ]);
}

/**
 * Search one direction of the slot for "victoria" (three stops across two places).
 */
function searchVictoria(string $slot = 'morning', string $direction = 'outward'): Testable
{
    fakeVictoria();

    return Livewire::test(StopScheduleEditor::class)
        ->set("query.{$slot}.{$direction}", 'victoria')
        ->call('search', $slot, $direction);
}

beforeEach(function () {
    $this->actingAs(makeAdmin());
});

it('renders on the admin page with both cards and the R1 link', function () {
    $token = app(DeviceToken::class)->current();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(StopScheduleEditor::class)
        ->assertSeeInOrder(['Morning stops', 'Outward', 'No stop set', 'Inward', 'No stop set'])
        ->assertSeeInOrder(['Afternoon stops', 'Outward', 'No stop set', 'Inward', 'No stop set'])
        ->assertSee(route('r1.show', ['token' => $token]));
});

it('lists every stop with its letter and destination for one direction of one slot only', function () {
    searchVictoria()
        ->assertSet('stops.morning.outward', [
            ['naptan_id' => '490014050A', 'name' => 'Victoria Bus Station', 'stop_letter' => 'A', 'towards' => 'Green Park'],
            ['naptan_id' => '490014050B', 'name' => 'Victoria Bus Station', 'stop_letter' => 'B', 'towards' => 'Pimlico'],
            ['naptan_id' => '490000248G', 'name' => 'Victoria Station', 'stop_letter' => 'G', 'towards' => 'Battersea'],
        ])
        ->assertSet('stops.morning.inward', [])
        ->assertSet('stops.afternoon.outward', [])
        ->assertSeeInOrder(['Victoria Bus Station', 'Stop A', 'towards Green Park'])
        ->assertSeeInOrder(['Victoria Station', 'Stop G', 'towards Battersea']);
});

it('says so when a search finds nothing', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response(['matches' => []])]);

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning.outward', 'zzzz')
        ->call('search', 'morning', 'outward')
        ->assertSet('problem.morning.outward', 'No stops found for "zzzz". Check the spelling or try a nearby landmark.');
});

it('asks for a name instead of calling TfL when the search is blank', function () {
    Http::fake();

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning.outward', '   ')
        ->call('search', 'morning', 'outward')
        ->assertSet('problem.morning.outward', 'Type a stop name to search.');

    Http::assertNothingSent();
});

it('says how many stops matched the search', function () {
    fakeVictoria();

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning.outward', ' victoria ')
        ->call('search', 'morning', 'outward')
        ->assertSee('Found 3 stops for "victoria". Pick the one you wait at:', false);
});

it('says so when the matching places have no bus stops', function () {
    Http::fake([
        'api.tfl.gov.uk/StopPoint/Search/*' => Http::response(['matches' => [['id' => '940GZZLUVIC', 'name' => 'Victoria Underground']]]),
        'api.tfl.gov.uk/StopPoint/940GZZLUVIC*' => Http::response(['naptanId' => '940GZZLUVIC', 'commonName' => 'Victoria Underground', 'stopType' => 'NaptanMetroStation']),
    ]);

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning.outward', 'victoria underground')
        ->call('search', 'morning', 'outward')
        ->assertSet('problem.morning.outward', 'No stops found for "victoria underground". Check the spelling or try a nearby landmark.');
});

it('clears a search without saving anything', function () {
    searchVictoria()
        ->call('cancel', 'morning', 'outward')
        ->assertSet('query.morning.outward', '')
        ->assertSet('stops.morning.outward', [])
        ->assertDontSee('Pick');

    expect(savedStops())->toBe([]);
});

it('confirms the stop it saved until the next search', function () {
    searchVictoria('afternoon')
        ->call('chooseStop', 'afternoon', 'outward', '490014050B')
        ->assertSet('notice.afternoon.outward', 'Saved Victoria Bus Station, Stop B.')
        ->assertSet('notice.afternoon.inward', null)
        ->assertSet('notice.morning.outward', null)
        ->assertSee('Saved Victoria Bus Station, Stop B.')
        ->set('query.afternoon.outward', 'victoria')
        ->call('search', 'afternoon', 'outward')
        ->assertSet('notice.afternoon.outward', null);
});

it('marks the search and the choices as busy while TfL is being asked', function () {
    searchVictoria()
        ->assertSeeHtml('wire:loading wire:target="search(\'morning\', \'outward\')"')
        ->assertSeeHtml('wire:loading.class="busy"')
        ->assertSee('Searching…');
});

it('saves only the stop that was chosen, with its letter and direction', function () {
    $component = searchVictoria('afternoon');

    expect(savedStops())->toBe([]);

    $component->call('chooseStop', 'afternoon', 'outward', '490014050B')
        ->assertSet('stops.afternoon.outward', []);

    expect(savedStops())->toEqual([
        new Stop(Slot::Afternoon, Direction::Outward, '490014050B', 'Victoria Bus Station', 'B', 'Pimlico'),
    ]);
});

it('replaces the existing stop for a slot instead of adding another', function () {
    saveStop(Slot::Morning, 'OLD', 'Old Stop');

    searchVictoria()->call('chooseStop', 'morning', 'outward', '490000248G');

    expect(savedStops())->toHaveCount(1)
        ->and(savedStops()[0]->naptanId)->toBe('490000248G');
});

it('saves the inward stop without touching the outward one', function () {
    saveStop(Slot::Morning, 'OUT', 'Outward Stop');

    searchVictoria('morning', 'inward')
        ->assertSet('stops.morning.outward', [])
        ->call('chooseStop', 'morning', 'inward', '490000248G')
        ->assertSet('notice.morning.inward', 'Saved Victoria Station, Stop G.')
        ->assertSet('notice.morning.outward', null)
        ->assertSeeInOrder(['Outward', 'Outward Stop', 'Inward', 'Victoria Station']);

    $store = app(BusStore::class);

    expect(savedStops())->toHaveCount(2)
        ->and($store->stop(Slot::Morning, Direction::Outward)->naptanId)->toBe('OUT')
        ->and($store->stop(Slot::Morning, Direction::Inward)->naptanId)->toBe('490000248G');
});

it('ignores a stop id that was not offered, an unknown slot and an unknown direction', function () {
    fakeVictoria();

    searchVictoria()
        ->call('chooseStop', 'morning', 'outward', '490000007F')
        ->call('chooseStop', 'evening', 'outward', '490014050A')
        ->call('chooseStop', 'morning', 'sideways', '490014050A')
        ->call('search', 'evening', 'outward')
        ->call('search', 'morning', 'sideways')
        ->call('cancel', 'morning', 'sideways')
        ->assertCount('stops.morning.outward', 3);

    expect(savedStops())->toBe([]);
    Http::assertSentCount(2);
});

it('shows an error and keeps the saved stop when TfL is down', function () {
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    Http::fake(['api.tfl.gov.uk/*' => Http::response('Bad gateway', 502)]);

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning.outward', 'angel')
        ->call('search', 'morning', 'outward')
        ->assertSet('problem.morning.outward', 'TfL is not answering. Try again in a moment.')
        ->assertSee('Angel Station');

    expect(savedStops()[0]->naptanId)->toBe('490000007F');
});

it('regenerates the R1 link', function () {
    $tokens = app(DeviceToken::class);
    $old = $tokens->current();

    Livewire::test(StopScheduleEditor::class)
        ->call('regenerateToken')
        ->assertDontSee($old);

    expect($tokens->matches($old))->toBeFalse();
});

it('uses the new stop on the next device request', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'Europe/London'));
    Http::fake([
        'api.tfl.gov.uk/StopPoint/490000007F/Arrivals*' => Http::response([]),
        'api.tfl.gov.uk/StopPoint/Search/*' => Http::response(['matches' => [['id' => '490000007F', 'name' => 'Angel Station']]]),
        'api.tfl.gov.uk/StopPoint/490000007F*' => Http::response(tflStop('490000007F', 'Angel Station', 'F', 'Holborn')),
    ]);

    $this->getJson(deviceUrl('/arrivals'))->assertJsonPath('state', 'no_stop');

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning.outward', 'angel')
        ->call('search', 'morning', 'outward')
        ->call('chooseStop', 'morning', 'outward', '490000007F');

    $this->getJson(deviceUrl('/arrivals'))
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('stop.name', 'Angel Station');
});
