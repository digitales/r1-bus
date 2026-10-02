<?php

use App\Data\Stop;
use App\Enums\Direction;
use App\Enums\Slot;
use App\Services\BusStore;
use App\Services\DeviceToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        Storage::fake('local');
        // The app has no database. Anything that reaches for one fails loudly.
        config(['database.default' => 'none']);
        Sleep::fake();
    })
    ->in('Feature', 'Unit');

/**
 * A TfL arrival prediction with only the fields the app reads.
 */
function tflPrediction(string $line, string $destination, DateTimeInterface $expected): array
{
    return [
        'lineName' => $line,
        'destinationName' => $destination,
        'expectedArrival' => gmdate('Y-m-d\TH:i:s\Z', $expected->getTimestamp()),
        'timeToStation' => $expected->getTimestamp() - now()->getTimestamp(),
    ];
}

function deviceUrl(string $path = ''): string
{
    return '/r1/'.app(DeviceToken::class)->current().$path;
}

/**
 * Save a stop the way the admin page would.
 */
function saveStop(Slot $slot, string $naptanId, string $name, Direction $direction = Direction::Outward): Stop
{
    $stop = new Stop($slot, $direction, $naptanId, $name, 'F', 'Holborn');
    app(BusStore::class)->saveStop($stop);

    return $stop;
}

/**
 * Every saved stop, in slot then direction order.
 *
 * @return list<Stop>
 */
function savedStops(): array
{
    return collect(app(BusStore::class)->stops())->flatten()->all();
}

/**
 * Store an admin login and return the user the app would sign in.
 */
function makeAdmin(string $email = 'ross@example.com', string $password = 'correct-horse-battery'): Authenticatable
{
    app(BusStore::class)->setAdmin($email, Hash::make($password));

    return Auth::getProvider()->retrieveById($email);
}
