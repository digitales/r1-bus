<?php

namespace App\Services;

use App\Data\Stop;
use App\Enums\Direction;
use App\Enums\Slot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Everything the app remembers, in one JSON file on the default disk: the
 * stops, the device token and the admin login.
 */
final class BusStore
{
    private const FILE = 'bus.json';

    private const CACHE_KEY = 'bus-store';

    private const CACHE_SECONDS = 60;

    public function stop(Slot $slot, Direction $direction): ?Stop
    {
        return $this->stops()[$slot->value][$direction->value] ?? null;
    }

    /**
     * @return array<string, array<string, Stop>> keyed by slot, then direction
     */
    public function stops(): array
    {
        $saved = $this->read()['stops'] ?? [];
        $stops = [];

        foreach (Slot::cases() as $slot) {
            foreach (Direction::cases() as $direction) {
                $stop = $saved[$slot->value][$direction->value] ?? null;

                // A stop without an id or a name cannot be shown, so it counts as unset.
                if (is_array($stop) && is_string($stop['naptan_id'] ?? null) && is_string($stop['name'] ?? null)) {
                    $stops[$slot->value][$direction->value] = new Stop(
                        $slot,
                        $direction,
                        $stop['naptan_id'],
                        $stop['name'],
                        $stop['stop_letter'] ?? null,
                        $stop['towards'] ?? null,
                    );
                }
            }
        }

        return $stops;
    }

    public function saveStop(Stop $stop): void
    {
        $this->write(function (array $data) use ($stop) {
            if (! is_array($data['stops'] ?? null)) {
                $data['stops'] = [];
            }

            if (! is_array($data['stops'][$stop->slot->value] ?? null)) {
                $data['stops'][$stop->slot->value] = [];
            }

            $data['stops'][$stop->slot->value][$stop->direction->value] = [
                'naptan_id' => $stop->naptanId,
                'name' => $stop->name,
                'stop_letter' => $stop->stopLetter,
                'towards' => $stop->towards,
            ];

            return $data;
        });
    }

    public function deviceToken(): ?string
    {
        $token = $this->read()['device_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function setDeviceToken(string $token): void
    {
        $this->write(fn (array $data) => ['device_token' => $token] + $data);
    }

    /**
     * @return array{email: string, password: string}|null
     */
    public function admin(): ?array
    {
        $admin = $this->read()['admin'] ?? null;

        if (! is_array($admin) || ! is_string($admin['email'] ?? null) || ! is_string($admin['password'] ?? null)) {
            return null;
        }

        return ['email' => $admin['email'], 'password' => $admin['password']];
    }

    public function setAdmin(string $email, string $passwordHash): void
    {
        $this->write(fn (array $data) => ['admin' => ['email' => $email, 'password' => $passwordHash]] + $data);
    }

    private function read(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->load());
    }

    /**
     * A file that is there but unreadable must never look like an empty one,
     * or the next write would replace what was saved.
     */
    private function load(): array
    {
        $disk = Storage::disk();

        if (! $disk->exists(self::FILE)) {
            return [];
        }

        $json = $disk->get(self::FILE);

        if ($json === null) {
            throw new RuntimeException(self::FILE.' exists but could not be read.');
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException(self::FILE.' is not valid JSON.');
        }

        return $data;
    }

    /**
     * @param  callable(array): array  $change
     */
    private function write(callable $change): void
    {
        $data = $change($this->load());

        // No visibility option: the bucket decides that, and R2 rejects it per file.
        if (! Storage::disk()->put(self::FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException(self::FILE.' could not be saved.');
        }

        Cache::forget(self::CACHE_KEY);
    }
}
