<?php

namespace App\Services;

use Illuminate\Http\Request;

interface VenueService {
    public function createVenue(Request $request);
    public function getAllVenues();
    public function getVenue($id);
    public function removeVenue($id);
    public function updateVenue(Request $request, $id);
}