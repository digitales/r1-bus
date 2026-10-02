<?php

namespace App\Http\Controllers;

use App\Enums\Direction;
use App\Exceptions\TflUnavailable;
use App\Services\ArrivalsResult;
use App\Services\ArrivalsService;
use App\Services\WindowResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function show(string $token): View
    {
        return view('r1', [
            'arrivalsUrl' => route('r1.arrivals', ['token' => $token]),
            'refreshSeconds' => config('bus.cache_seconds'),
        ]);
    }

    public function arrivals(Request $request, WindowResolver $windows, ArrivalsService $arrivals): JsonResponse
    {
        $now = CarbonImmutable::now();
        $slot = $windows->current($now);

        if ($slot === null && ! $request->boolean('check')) {
            return response()->json($this->outsideWindow($now, $windows->nextStart($now)));
        }

        $slot ??= $windows->upcoming($now);
        $direction = Direction::tryFrom((string) $request->query('direction')) ?? Direction::Outward;

        try {
            $result = $arrivals->forSlot($slot, $direction);
        } catch (TflUnavailable) {
            return response()->json($this->payload('unavailable', ['direction' => $direction->value]));
        }

        if ($result === null) {
            return response()->json($this->payload('no_stop', ['direction' => $direction->value]));
        }

        return response()->json($this->live($now, $result));
    }

    private function live(CarbonImmutable $now, ArrivalsResult $result): array
    {
        $fetchedAt = $result->fetchedAt->setTimezone(config('bus.timezone'));

        return $this->payload('live', [
            'direction' => $result->stop->direction->value,
            'stop' => [
                'name' => $result->stop->name,
                'letter' => $result->stop->stop_letter,
                'towards' => $result->stop->towards,
            ],
            'arrivals' => $result->arrivals,
            'fetched_at' => $fetchedAt->toIso8601String(),
            'fetched_label' => $fetchedAt->format('H:i'),
            'stale' => $result->stale,
            'stale_minutes' => $result->stale
                ? intdiv($now->getTimestamp() - $fetchedAt->getTimestamp(), 60)
                : 0,
        ]);
    }

    private function outsideWindow(CarbonImmutable $now, CarbonImmutable $nextStart): array
    {
        $today = $now->setTimezone(config('bus.timezone'));

        return $this->payload('outside_window', [
            'next_window' => $nextStart->toIso8601String(),
            'next_window_label' => $nextStart->isSameDay($today)
                ? 'Next check '.$nextStart->format('H:i')
                : 'Back '.$nextStart->format('l H:i'),
        ]);
    }

    private function payload(string $state, array $overrides = []): array
    {
        return array_merge([
            'state' => $state,
            'direction' => null,
            'stop' => null,
            'arrivals' => [],
            'fetched_at' => null,
            'fetched_label' => null,
            'stale' => false,
            'stale_minutes' => 0,
            'next_window' => null,
            'next_window_label' => null,
        ], $overrides);
    }
}
