<?php

namespace App\Enums;

enum Direction: string
{
    case Outward = 'outward';
    case Inward = 'inward';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
