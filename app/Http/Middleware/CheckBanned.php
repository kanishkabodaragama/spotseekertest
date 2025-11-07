<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class CheckBanned
{
    use ApiResponse;
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check() && (auth()->user()->status == 0)) {

            $request->session()->invalidate();

            $request->session()->regenerateToken();

            $response = (object) [
                "message" => 'Your Account is suspended, please contact SpotSeeker.lk.',
                "status" => false,
                "code" => 401
            ];

            return $this->generateResponse($response);
        
        } else {
            return $next($request);
        }

    }
}
