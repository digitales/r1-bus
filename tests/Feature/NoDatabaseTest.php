<?php

use App\Enums\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('has no database to fall back on in tests', function () {
    expect(fn () => DB::connection()->getPdo())->toThrow(InvalidArgumentException::class);
});

it('serves the device and the admin page without a database', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'Europe/London'));
    saveStop(Slot::Morning, '490000007F', 'Angel Station');
    Http::fake(['api.tfl.gov.uk/*' => Http::response([])]);

    $this->get(deviceUrl())->assertOk();
    $this->getJson(deviceUrl('/arrivals'))->assertOk()->assertJsonPath('state', 'live');

    $this->post('/login', ['email' => makeAdmin()->email, 'password' => 'correct-horse-battery'])
        ->assertRedirect('/admin');
    $this->get('/admin')->assertOk()->assertSee('Angel Station');
});

it('ships with drivers that need no database', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain('SESSION_DRIVER=cookie')
        ->toContain('CACHE_STORE=file')
        ->toContain('QUEUE_CONNECTION=sync')
        ->not->toContain('DB_CONNECTION');
});
