<?php

namespace App\Livewire;

use App\Enums\Direction;
use App\Enums\Slot;
use App\Exceptions\TflUnavailable;
use App\Models\StopSchedule;
use App\Services\DeviceToken;
use App\Services\TflClient;
use Illuminate\View\View;
use Livewire\Component;

class StopScheduleEditor extends Component
{
    // Each is keyed by slot, then by direction.
    public array $query = [];

    public array $stops = [];

    public array $problem = [];

    public array $notice = [];

    public array $searched = [];

    public function mount(): void
    {
        foreach (Slot::cases() as $slot) {
            foreach (Direction::cases() as $direction) {
                $this->query[$slot->value][$direction->value] = '';
                $this->clear($slot, $direction);
            }
        }
    }

    public function search(string $slot, string $direction): void
    {
        if (! [$slot, $direction] = $this->target($slot, $direction)) {
            return;
        }

        $this->clear($slot, $direction);

        $term = trim((string) $this->query[$slot->value][$direction->value]);

        if ($term === '') {
            $this->problem[$slot->value][$direction->value] = 'Type a stop name to search.';

            return;
        }

        try {
            $stops = app(TflClient::class)->searchStops($term);
        } catch (TflUnavailable) {
            $this->problem[$slot->value][$direction->value] = 'TfL is not answering. Try again in a moment.';

            return;
        }

        if ($stops === []) {
            $this->problem[$slot->value][$direction->value] = "No stops found for \"{$term}\". Check the spelling or try a nearby landmark.";
        }

        $this->searched[$slot->value][$direction->value] = $term;
        $this->stops[$slot->value][$direction->value] = $stops;
    }

    public function chooseStop(string $slot, string $direction, string $naptanId): void
    {
        if (! [$slot, $direction] = $this->target($slot, $direction)) {
            return;
        }

        foreach ($this->stops[$slot->value][$direction->value] as $stop) {
            if ($stop['naptan_id'] === $naptanId) {
                $this->save($slot, $direction, $stop);

                return;
            }
        }
    }

    public function cancel(string $slot, string $direction): void
    {
        if (! [$slot, $direction] = $this->target($slot, $direction)) {
            return;
        }

        $this->query[$slot->value][$direction->value] = '';
        $this->clear($slot, $direction);
    }

    public function regenerateToken(): void
    {
        app(DeviceToken::class)->regenerate();
    }

    public function render(): View
    {
        $saved = [];

        foreach (StopSchedule::all() as $stop) {
            $saved[$stop->slot->value][$stop->direction->value] = $stop;
        }

        return view('livewire.stop-schedule-editor', [
            'cases' => Slot::cases(),
            'directions' => Direction::cases(),
            'saved' => $saved,
            'deviceUrl' => route('r1.show', ['token' => app(DeviceToken::class)->current()]),
        ]);
    }

    /**
     * @return array{0: Slot, 1: Direction}|null
     */
    private function target(string $slot, string $direction): ?array
    {
        $slot = Slot::tryFrom($slot);
        $direction = Direction::tryFrom($direction);

        return $slot && $direction ? [$slot, $direction] : null;
    }

    private function save(Slot $slot, Direction $direction, array $stop): void
    {
        StopSchedule::updateOrCreate(['slot' => $slot->value, 'direction' => $direction->value], [
            'naptan_id' => $stop['naptan_id'],
            'name' => $stop['name'],
            'stop_letter' => $stop['stop_letter'],
            'towards' => $stop['towards'],
        ]);

        $this->query[$slot->value][$direction->value] = '';
        $this->clear($slot, $direction);
        $this->notice[$slot->value][$direction->value] = 'Saved '.$stop['name'].($stop['stop_letter'] ? ', Stop '.$stop['stop_letter'] : '').'.';
    }

    private function clear(Slot $slot, Direction $direction): void
    {
        $this->stops[$slot->value][$direction->value] = [];
        $this->problem[$slot->value][$direction->value] = null;
        $this->notice[$slot->value][$direction->value] = null;
        $this->searched[$slot->value][$direction->value] = null;
    }
}
