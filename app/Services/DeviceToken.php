<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

final class DeviceToken
{
    public function current(): string
    {
        return $this->setting()->device_token;
    }

    public function regenerate(): string
    {
        $setting = $this->setting();
        $setting->update(['device_token' => Str::random(48)]);

        return $setting->device_token;
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->current(), $candidate);
    }

    private function setting(): Setting
    {
        return Setting::query()->first() ?? Setting::create(['device_token' => Str::random(48)]);
    }
}
