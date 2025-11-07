<?php

namespace App\Services;

use Illuminate\Http\Request;

interface TicketPackageService {
    public function createTicketPackage(Request $request);
}