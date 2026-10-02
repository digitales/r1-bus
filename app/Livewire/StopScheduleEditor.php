<?php

namespace App\Livewire;

use App\Enums\Slot;
use App\Exceptions\TflUnavailable;
use App\Models\StopSchedule;
use App\Services\DeviceToken;
use App\Services\TflClient;
use Illuminate\View\View;
use Livewire\Component;

class StopScheduleEditor extends Component
{
    public array $query = ['morning' => '', 'afternoon' => ''];

    public array $places = ['morning' => [], 'afternoon' => []];

    public array $stops = ['morning' => [], 'afternoon' => []];

    public array $problem = ['morning' => null, 'afternoon' => null];

    public function search(string $slot): void
    {
        if (! $slot = Slot::tryFrom($slot)) {
            return;
        }

        $this->clear($slot);

        try {
            $places = app(TflClient::class)->searchStops((string) $this->query[$slot->value]);
        } catch (TflUnavailable) {
            $this->problem[$slot->value] = 'TfL is not answering. Try again in a moment.';

            return;
        }

        if ($places === []) {
            $this->problem[$slot->value] = 'No stops found for that name.';
        }

        $this->places[$slot->value] = $places;
    }

    public function choosePlace(string $slot, string $id): void
    {
        if (! $slot = Slot::tryFrom($slot)) {
            return;
        }

        $this->problem[$slot->value] = null;

        try {
            $stops = app(TflClient::class)->stopsAt($id);
        } catch (TflUnavailable) {
            $this->problem[$slot->value] = 'TfL is not answering. Try again in a moment.';

            return;
        }

        if ($stops === []) {
            $this->problem[$slot->value] = 'No bus stops found at that place.';

            return;
        }

        if (count($stops) === 1) {
            $this->save($slot, $stops[0]);

            return;
        }

        $this->stops[$slot->value] = $stops;
    }

    public function chooseStop(string $slot, string $naptanId): void
    {
        if (! $slot = Slot::tryFrom($slot)) {
            return;
        }

        foreach ($this->stops[$slot->value] as $stop) {
            if ($stop['naptan_id'] === $naptanId) {
                $this->save($slot, $stop);

                return;
            }
        }
    }

    public function regenerateToken(): void
    {
        app(DeviceToken::class)->regenerate();
    }

    public function render(): View
    {
        return view('livewire.stop-schedule-editor', [
            'cases' => Slot::cases(),
            'saved' => StopSchedule::all()->keyBy(fn (StopSchedule $stop) => $stop->slot->value),
            'deviceUrl' => route('r1.show', ['token' => app(DeviceToken::class)->current()]),
        ]);
    }

    private function save(Slot $slot, array $stop): void
    {
        StopSchedule::updateOrCreate(['slot' => $slot->value], [
            'naptan_id' => $stop['naptan_id'],
            'name' => $stop['name'],
            'stop_letter' => $stop['stop_letter'],
            'towards' => $stop['towards'],
        ]);

        $this->query[$slot->value] = '';
        $this->clear($slot);
    }

    private function clear(Slot $slot): void
    {
        $this->places[$slot->value] = [];
        $this->stops[$slot->value] = [];
        $this->problem[$slot->value] = null;
    }
}
