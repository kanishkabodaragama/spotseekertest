<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\VenueService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    use ApiResponse;

    private VenueService $venueRepository;

    public function __construct(VenueService $venueRepository)
    {
        $this->venueRepository = $venueRepository;
    }

    public function store(Request $request) {
        $response = $this->venueRepository->createVenue($request);
        return $this->generateResponse($response);
    }

    public function index() {
        $response = $this->venueRepository->getAllVenues();
        return $this->generateResponse($response);
    }

    public function destroy($id)
    {
        $response = $this->venueRepository->removeVenue($id);
        return $this->generateResponse($response);
    }

    public function show($id)
    {
        $response = $this->venueRepository->getVenue($id);
        return $this->generateResponse($response);
    }

    public function update(Request $request, $id)
    {
        $response = $this->venueRepository->updateVenue($request, $id);
        return $this->generateResponse($response);
    }
}
