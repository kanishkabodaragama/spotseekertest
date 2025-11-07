<?php

namespace App\Traits;

use App\Enums\Roles;
use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;

trait CheckManager
{
    use ApiResponse;

    public function checkManager($event_id)
    {
        $user = Auth::id();

        $event = Event::where([
            ['id', $event_id],
            ['manager', $user]
        ])->get();

        if($event || Auth::user()->hasRole(Roles::ADMIN->value)) {
            return true;
        } else {
            return false;
        }
    }
}
