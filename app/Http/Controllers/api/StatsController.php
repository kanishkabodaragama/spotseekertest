<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\EventService;
use App\Services\StatsService;
use App\Services\VenueService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    use ApiResponse;

    private StatsService $statsRepository;

    public function __construct(EventService $eventRepository, VenueService $venueRepository, StatsService $statsRepository)
    {
        $this->eventRepository = $eventRepository;
        $this->venueRepository = $venueRepository;
        $this->statsRepository = $statsRepository;
    }

    public function getBasicStats(Request $request)
    {
        $response = $this->statsRepository->getBasicStats($request);
        return $this->generateResponse($response);
    }
}
