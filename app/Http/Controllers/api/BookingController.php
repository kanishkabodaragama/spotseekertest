<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\EventService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponse;

    private BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function store(Request $request)
    {
        $response = $this->bookingService->createBooking($request);
        return $this->generateResponse($response);
    }

    public function updateBooking(Request $request)
    {
        $response = $this->bookingService->updateBooking($request);
        return $this->generateResponse($response);
    }

    public function index(Request $request)
    {
        $response = $this->bookingService->getAllBookings($request);
        return $this->generateResponse($response);
    }

    public function verifyBooking(Request $request)
    {
        $response = $this->bookingService->verifyBooking($request);
        return $this->generateResponse($response);
    }

    public function update(Request $request, $id)
    {
        $response = $this->bookingService->updateBookingData($request, $id);
        return $this->generateResponse($response);
    }

    public function getBooking(Request $request, $id)
    {
        $response = $this->bookingService->getBooking($request, $id);
        return $this->generateResponse($response);
    }

    public function generateETicket($id) {
        $response = $this->bookingService->generateETicket($id);
        return $this->generateResponse($response);
    }

    public function generateSubBookings($id) {
        $response = $this->bookingService->generateSubBookings($id);
        return $this->generateResponse($response);
    }
    
    public function updateStatus(Request $request, $id) {
        $response = $this->bookingService->updateStatus($request, $id);
        return $this->generateResponse($response);
    }
}
