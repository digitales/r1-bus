<?php

use App\Data\Stop;
use App\Enums\Direction;
use App\Enums\Slot;
use App\Services\BusStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

function angel(Slot $slot = Slot::Morning, Direction $direction = Direction::Outward): Stop
{
    return new Stop($slot, $direction, '490000007F', 'Angel Station', 'F', 'Holborn');
}

/**
 * Swap the default disk for one that behaves like a bucket in trouble.
 */
function brokenDisk(?string $get = null, bool $put = false): Filesystem
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('get')->andReturn($get);
    $disk->shouldReceive('put')->andReturn($put)->byDefault();
    Storage::shouldReceive('disk')->andReturn($disk);

    return $disk;
}

it('reads a missing file as empty', function () {
    $store = app(BusStore::class);

    expect($store->stops())->toBe([])
        ->and($store->stop(Slot::Morning, Direction::Outward))->toBeNull()
        ->and($store->deviceToken())->toBeNull()
        ->and($store->admin())->toBeNull();

    Storage::disk()->assertMissing('bus.json');
});

it('round trips a stop with its letter and destination', function () {
    $store = app(BusStore::class);

    $store->saveStop(angel(Slot::Afternoon, Direction::Inward));

    expect($store->stop(Slot::Afternoon, Direction::Inward))->toEqual(angel(Slot::Afternoon, Direction::Inward))
        ->and($store->stop(Slot::Afternoon, Direction::Outward))->toBeNull()
        ->and($store->stop(Slot::Morning, Direction::Inward))->toBeNull()
        ->and(array_keys($store->stops()))->toBe(['afternoon'])
        ->and($store->stops()['afternoon']['inward']->name)->toBe('Angel Station');
});

it('round trips the token and the admin', function () {
    $store = app(BusStore::class);

    $store->setDeviceToken('token-token');
    $store->setAdmin('ross@example.com', 'hash');

    expect($store->deviceToken())->toBe('token-token')
        ->and($store->admin())->toBe(['email' => 'ross@example.com', 'password' => 'hash']);
});

it('leaves everything else in place when one stop is saved', function () {
    $store = app(BusStore::class);
    $store->setDeviceToken('token-token');
    $store->setAdmin('ross@example.com', 'hash');
    $store->saveStop(angel());

    $store->saveStop(new Stop(Slot::Morning, Direction::Inward, '490000129E', 'Kings Cross Station'));

    expect($store->stop(Slot::Morning, Direction::Outward)->name)->toBe('Angel Station')
        ->and($store->stop(Slot::Morning, Direction::Inward)->stopLetter)->toBeNull()
        ->and($store->deviceToken())->toBe('token-token')
        ->and($store->admin()['email'])->toBe('ross@example.com');
});

it('shows a write on the next read even though reads are cached', function () {
    $store = app(BusStore::class);

    expect($store->deviceToken())->toBeNull();
    $store->setDeviceToken('token-token');

    expect($store->deviceToken())->toBe('token-token');
});

it('reads the disk at most once a minute', function () {
    $store = app(BusStore::class);
    $store->setDeviceToken('token-token');
    expect($store->deviceToken())->toBe('token-token');

    Storage::disk()->delete('bus.json');
    $this->travel(59)->seconds();
    expect($store->deviceToken())->toBe('token-token');

    $this->travel(2)->seconds();
    expect($store->deviceToken())->toBeNull();
});

it('throws on invalid JSON and leaves the file as it was', function () {
    Storage::disk()->put('bus.json', '{"device_token": "abc"');
    $store = app(BusStore::class);

    expect(fn () => $store->deviceToken())->toThrow(RuntimeException::class)
        ->and(fn () => $store->setDeviceToken('new'))->toThrow(RuntimeException::class)
        ->and(Storage::disk()->get('bus.json'))->toBe('{"device_token": "abc"');
});

it('reads a stop missing its id or name as unset', function () {
    Storage::disk()->put('bus.json', json_encode(['stops' => [
        'morning' => ['outward' => ['name' => 'No Id'], 'inward' => ['naptan_id' => '490000007F']],
        'evening' => ['outward' => ['naptan_id' => 'X', 'name' => 'Unknown slot']],
        'afternoon' => 'not-a-list',
    ]]));
    $store = app(BusStore::class);

    expect($store->stops())->toBe([])
        ->and($store->stop(Slot::Morning, Direction::Outward))->toBeNull();
});

it('throws when a write fails', function () {
    brokenDisk(get: '{}', put: false);

    expect(fn () => app(BusStore::class)->setDeviceToken('token-token'))->toThrow(RuntimeException::class);
});

it('throws when the file exists but cannot be read, and does not overwrite it', function () {
    $disk = brokenDisk(get: null);
    $disk->shouldNotReceive('put');
    $store = app(BusStore::class);

    expect(fn () => $store->deviceToken())->toThrow(RuntimeException::class)
        ->and(fn () => $store->setDeviceToken('token-token'))->toThrow(RuntimeException::class);
});
