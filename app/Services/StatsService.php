<?php

namespace App\Services;

use Illuminate\Http\Request;

interface StatsService {
    public function getBasicStats(Request $request);
}