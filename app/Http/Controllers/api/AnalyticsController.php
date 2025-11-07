<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\EventService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnalyticsController extends Controller
{
    use ApiResponse;

    private EventService $eventService;

    public function __construct(EventService $eventService)
    {
        $this->eventService = $eventService;
    }

    public function addPixelCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'required|string',
            'pixel_code' => 'required|string',
            'event_id' => 'required|integer|exists:events,id'
        ]);

        if ($validator->fails()) {
            return (object) [
                "message" => 'validation failed',
                "status" => false,
                "errors" => $validator->messages()->toArray(),
                "code" => 422,
                "data" => []
            ];
        }

        $response = $this->eventService->addPixelCodes($request);
        return $this->generateResponse($response);
    }
}
