<?php

use App\Services\BusStore;
use App\Services\DeviceToken;

it('creates a long token on first use and keeps it', function () {
    $tokens = app(DeviceToken::class);

    $first = $tokens->current();

    expect(strlen($first))->toBe(48)
        ->and($tokens->current())->toBe($first)
        ->and(app(BusStore::class)->deviceToken())->toBe($first);
});

it('matches only the current token', function () {
    $tokens = app(DeviceToken::class);
    $token = $tokens->current();

    expect($tokens->matches($token))->toBeTrue()
        ->and($tokens->matches('wrong'))->toBeFalse()
        ->and($tokens->matches(''))->toBeFalse();
});

it('invalidates the old token when regenerated', function () {
    $tokens = app(DeviceToken::class);
    $old = $tokens->current();

    $new = $tokens->regenerate();

    expect($new)->not->toBe($old)
        ->and($tokens->matches($old))->toBeFalse()
        ->and($tokens->matches($new))->toBeTrue()
        ->and(app(BusStore::class)->deviceToken())->toBe($new);
});
