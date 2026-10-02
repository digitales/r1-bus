<?php

namespace App\Models;

use App\Enums\Slot;
use Illuminate\Database\Eloquent\Model;

class StopSchedule extends Model
{
    protected $fillable = ['slot', 'naptan_id', 'name', 'stop_letter', 'towards'];

    protected function casts(): array
    {
        return ['slot' => Slot::class];
    }
}
