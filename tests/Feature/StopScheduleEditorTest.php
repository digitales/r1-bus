<?php

use App\Enums\Slot;
use App\Livewire\StopScheduleEditor;
use App\Models\StopSchedule;
use App\Models\User;
use App\Services\DeviceToken;
use Illuminate\Support\Facades\Http;
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

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders on the admin page with both cards and the R1 link', function () {
    $token = app(DeviceToken::class)->current();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(StopScheduleEditor::class)
        ->assertSee('Morning stop')
        ->assertSee('Afternoon stop')
        ->assertSee('No stop set')
        ->assertSee(route('r1.show', ['token' => $token]));
});

it('searches and lists places for one slot only', function () {
    Http::fake(['api.tfl.gov.uk/StopPoint/Search/*' => Http::response(['matches' => [
        ['id' => 'HUBVIC', 'name' => 'Victoria'],
        ['id' => '490000248G', 'name' => 'Victoria Station', 'towards' => 'Battersea'],
    ]])]);

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning', 'victoria')
        ->call('search', 'morning')
        ->assertSet('places.morning', [
            ['id' => 'HUBVIC', 'name' => 'Victoria', 'towards' => null],
            ['id' => '490000248G', 'name' => 'Victoria Station', 'towards' => 'Battersea'],
        ])
        ->assertSet('places.afternoon', [])
        ->assertSee('towards Battersea');
});

it('says so when a search finds nothing', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response(['matches' => []])]);

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning', 'zzzz')
        ->call('search', 'morning')
        ->assertSet('problem.morning', 'No stops found for that name.');
});

it('saves straight away when the place is a single stop', function () {
    Http::fake(['api.tfl.gov.uk/StopPoint/490000007F*' => Http::response(
        tflStop('490000007F', 'Angel Station', 'F', 'Holborn')
    )]);

    Livewire::test(StopScheduleEditor::class)
        ->call('choosePlace', 'morning', '490000007F')
        ->assertSet('stops.morning', [])
        ->assertSee('Angel Station')
        ->assertSee('Stop F');

    $saved = StopSchedule::sole();
    expect($saved->slot)->toBe(Slot::Morning)
        ->and($saved->only(['naptan_id', 'name', 'stop_letter', 'towards']))->toBe([
            'naptan_id' => '490000007F',
            'name' => 'Angel Station',
            'stop_letter' => 'F',
            'towards' => 'Holborn',
        ]);
});

it('asks which stop when a hub holds several, then saves the chosen one', function () {
    Http::fake(['api.tfl.gov.uk/StopPoint/HUBVIC*' => Http::response([
        'naptanId' => 'HUBVIC',
        'commonName' => 'Victoria',
        'stopType' => 'TransportInterchange',
        'children' => [
            tflStop('490014050A', 'Victoria Bus Station', 'A', 'Green Park'),
            tflStop('490014050B', 'Victoria Bus Station', 'B', 'Pimlico'),
        ],
    ])]);

    $component = Livewire::test(StopScheduleEditor::class)
        ->call('choosePlace', 'afternoon', 'HUBVIC')
        ->assertCount('stops.afternoon', 2)
        ->assertSee('towards Pimlico');

    expect(StopSchedule::count())->toBe(0);

    $component->call('chooseStop', 'afternoon', '490014050B')
        ->assertSet('stops.afternoon', [])
        ->assertSet('places.afternoon', []);

    expect(StopSchedule::sole()->only(['naptan_id', 'stop_letter']))
        ->toBe(['naptan_id' => '490014050B', 'stop_letter' => 'B'])
        ->and(StopSchedule::sole()->slot)->toBe(Slot::Afternoon);
});

it('replaces the existing stop for a slot instead of adding another', function () {
    StopSchedule::create(['slot' => Slot::Morning, 'naptan_id' => 'OLD', 'name' => 'Old Stop']);
    Http::fake(['api.tfl.gov.uk/StopPoint/490000007F*' => Http::response(
        tflStop('490000007F', 'Angel Station', 'F', 'Holborn')
    )]);

    Livewire::test(StopScheduleEditor::class)->call('choosePlace', 'morning', '490000007F');

    expect(StopSchedule::count())->toBe(1)
        ->and(StopSchedule::sole()->naptan_id)->toBe('490000007F');
});

it('reports a place with no bus stops', function () {
    Http::fake(['api.tfl.gov.uk/*' => Http::response([
        'naptanId' => '490G000700',
        'commonName' => 'Somewhere',
        'stopType' => 'NaptanOnstreetBusCoachStopPair',
        'children' => [],
    ])]);

    Livewire::test(StopScheduleEditor::class)
        ->call('choosePlace', 'morning', '490G000700')
        ->assertSet('problem.morning', 'No bus stops found at that place.');

    expect(StopSchedule::count())->toBe(0);
});

it('ignores a stop id that was not offered and an unknown slot', function () {
    Http::fake();

    Livewire::test(StopScheduleEditor::class)
        ->call('chooseStop', 'morning', '490000007F')
        ->call('chooseStop', 'evening', '490000007F')
        ->call('search', 'evening')
        ->call('choosePlace', 'evening', 'HUBVIC');

    expect(StopSchedule::count())->toBe(0);
    Http::assertNothingSent();
});

it('shows an error and keeps the saved stop when TfL is down', function () {
    StopSchedule::create(['slot' => Slot::Morning, 'naptan_id' => '490000007F', 'name' => 'Angel Station']);
    Http::fake(['api.tfl.gov.uk/*' => Http::response('Bad gateway', 502)]);

    Livewire::test(StopScheduleEditor::class)
        ->set('query.morning', 'angel')
        ->call('search', 'morning')
        ->assertSet('problem.morning', 'TfL is not answering. Try again in a moment.')
        ->assertSee('Angel Station');

    expect(StopSchedule::sole()->naptan_id)->toBe('490000007F');
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
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-05 07:00:00', 'Europe/London'));
    Http::fake([
        'api.tfl.gov.uk/StopPoint/490000007F/Arrivals*' => Http::response([]),
        'api.tfl.gov.uk/StopPoint/490000007F*' => Http::response(tflStop('490000007F', 'Angel Station', 'F', 'Holborn')),
    ]);

    $this->getJson(deviceUrl('/arrivals'))->assertJsonPath('state', 'no_stop');

    Livewire::test(StopScheduleEditor::class)->call('choosePlace', 'morning', '490000007F');

    $this->getJson(deviceUrl('/arrivals'))
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('stop.name', 'Angel Station');
});
