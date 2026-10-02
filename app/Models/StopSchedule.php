<?php

namespace App\Models;

use App\Enums\Direction;
use App\Enums\Slot;
use Illuminate\Database\Eloquent\Model;

class StopSchedule extends Model
{
    protected $fillable = ['slot', 'direction', 'naptan_id', 'name', 'stop_letter', 'towards'];

    protected function casts(): array
    {
        return ['slot' => Slot::class, 'direction' => Direction::class];
    }
}
