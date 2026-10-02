<?php

namespace App\Services;

use App\Enums\Slot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

final class WindowResolver
{
    public function current(CarbonInterface $now): ?Slot
    {
        $local = $this->local($now);

        if (! $this->isActiveDay($local)) {
            return null;
        }

        foreach (Slot::cases() as $slot) {
            if ($local->gte($this->edge($local, $slot, 'start')) && $local->lt($this->edge($local, $slot, 'end'))) {
                return $slot;
            }
        }

        return null;
    }

    public function upcoming(CarbonInterface $now): Slot
    {
        return $this->next($now)[0];
    }

    public function nextStart(CarbonInterface $now): CarbonImmutable
    {
        return $this->next($now)[1];
    }

    /**
     * @return array{0: Slot, 1: CarbonImmutable}
     */
    private function next(CarbonInterface $now): array
    {
        $local = $this->local($now);

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $local->addDays($offset);

            if (! $this->isActiveDay($day)) {
                continue;
            }

            foreach (Slot::cases() as $slot) {
                $start = $this->edge($day, $slot, 'start');

                if ($start->gt($local)) {
                    return [$slot, $start];
                }
            }
        }

        throw new LogicException('bus.weekdays has no active day.');
    }

    private function local(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone(config('bus.timezone'));
    }

    private function edge(CarbonImmutable $day, Slot $slot, string $edge): CarbonImmutable
    {
        return $day->setTimeFromTimeString(config("bus.windows.{$slot->value}.{$edge}"));
    }

    private function isActiveDay(CarbonImmutable $day): bool
    {
        return in_array($day->dayOfWeekIso, config('bus.weekdays'), true);
    }
}
