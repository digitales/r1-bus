<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\Slot;
use App\Exceptions\TflUnavailable;
use App\Models\StopSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ArrivalsService
{
    public function __construct(private TflClient $tfl) {}

    public function forSlot(Slot $slot, Direction $direction = Direction::Outward): ?ArrivalsResult
    {
        $stop = StopSchedule::query()
            ->where('slot', $slot->value)
            ->where('direction', $direction->value)
            ->first();

        if ($stop === null) {
            return null;
        }

        $freshKey = "arrivals:fresh:{$stop->naptan_id}";
        $lastGoodKey = "arrivals:last-good:{$stop->naptan_id}";

        if ($snapshot = Cache::get($freshKey)) {
            return $this->result($stop, $snapshot, stale: false);
        }

        try {
            $snapshot = [
                'fetched_at' => now()->getTimestamp(),
                'arrivals' => $this->snapshot($this->tfl->arrivals($stop->naptan_id)),
            ];
        } catch (TflUnavailable $exception) {
            $lastGood = Cache::get($lastGoodKey);

            if ($lastGood === null) {
                throw $exception;
            }

            return $this->result($stop, $lastGood, stale: true);
        }

        Cache::put($freshKey, $snapshot, config('bus.cache_seconds'));
        Cache::put($lastGoodKey, $snapshot, now()->addMinutes(config('bus.stale_minutes')));

        return $this->result($stop, $snapshot, stale: false);
    }

    /**
     * @return list<array{route: string, destination: string, expected_at: int}>
     */
    private function snapshot(array $predictions): array
    {
        $arrivals = [];

        foreach ($predictions as $prediction) {
            if (! is_array($prediction) || ! isset($prediction['lineName'], $prediction['expectedArrival'])) {
                continue;
            }

            try {
                $expectedAt = CarbonImmutable::parse($prediction['expectedArrival'])->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            $arrivals[] = [
                'route' => (string) $prediction['lineName'],
                'destination' => (string) ($prediction['destinationName'] ?? ''),
                'expected_at' => $expectedAt,
            ];
        }

        usort($arrivals, fn (array $a, array $b) => $a['expected_at'] <=> $b['expected_at']);

        return $arrivals;
    }

    private function result(StopSchedule $stop, array $snapshot, bool $stale): ArrivalsResult
    {
        $now = now()->getTimestamp();
        $arrivals = [];

        foreach ($snapshot['arrivals'] as $arrival) {
            $seconds = $arrival['expected_at'] - $now;

            // A bus more than 30 seconds past its expected time has gone.
            if ($seconds < -30) {
                continue;
            }

            $arrivals[] = [
                'route' => $arrival['route'],
                'destination' => $arrival['destination'],
                'minutes' => max(0, intdiv($seconds, 60)),
            ];
        }

        return new ArrivalsResult(
            stop: $stop,
            arrivals: array_slice($arrivals, 0, config('bus.max_arrivals')),
            fetchedAt: CarbonImmutable::createFromTimestamp($snapshot['fetched_at']),
            stale: $stale,
        );
    }
}
