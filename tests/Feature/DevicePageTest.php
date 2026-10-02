<?php

use App\Services\DeviceToken;

it('serves the creation page with its arrivals url', function () {
    $token = app(DeviceToken::class)->current();

    $this->get("/r1/{$token}")
        ->assertOk()
        ->assertViewIs('r1')
        ->assertViewHas('arrivalsUrl', route('r1.arrivals', ['token' => $token]))
        ->assertViewHas('refreshSeconds', 20)
        ->assertSee('width=240', false)
        ->assertSee('sideClick', false)
        ->assertSee('scrollUp', false)
        ->assertSee('scrollDown', false)
        ->assertSee('Check now', false);
});

it('returns 404 for the page with a wrong token', function () {
    $this->get('/r1/not-the-token')->assertNotFound();
});

it('does not expose the TfL key to the device', function () {
    config(['services.tfl.app_key' => 'secret-key']);

    $this->get(deviceUrl())->assertDontSee('secret-key', false);
});
