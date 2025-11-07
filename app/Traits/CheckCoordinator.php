<?php

namespace App\Traits;

use App\Models\Event;
use App\Models\EventCoordinator;
use Closure;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;

trait CheckCoordinator
{
    use ApiResponse;

    public function checkCoordinator($event_id)
    {
        $user = Auth::id();

        $event = EventCoordinator::where([
            ['event_id', $event_id],
            ['user_id', $user]
        ])->first();

        if($event) {
            return true;
        } else {
            return false;
        }
    }
}
