<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
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
    return '/r1/'.app(App\Services\DeviceToken::class)->current().$path;
}
