<?php

namespace App\Enums;

enum Slot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
