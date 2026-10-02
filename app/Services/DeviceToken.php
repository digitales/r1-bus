<?php

namespace App\Services;

use Illuminate\Support\Str;

final class DeviceToken
{
    public function __construct(private BusStore $store) {}

    public function current(): string
    {
        return $this->store->deviceToken() ?? $this->regenerate();
    }

    public function regenerate(): string
    {
        $token = Str::random(48);
        $this->store->setDeviceToken($token);

        return $token;
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->current(), $candidate);
    }
}
