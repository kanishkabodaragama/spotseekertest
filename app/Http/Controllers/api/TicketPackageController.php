<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\TicketPackageService;
use Illuminate\Http\Request;

class TicketPackageController extends Controller
{
    private TicketPackageService $ticketPackageRepository;

    public function __construct(TicketPackageService $ticketPackageRepository)
    {
        $this->ticketPackageRepository = $ticketPackageRepository;
    }

    public function store(Request $request) {
        return $this->ticketPackageRepository->createTicketPackage($request);
    }
}
