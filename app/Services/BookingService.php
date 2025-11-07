<?php 

namespace App\Services;

use Illuminate\Http\Request;

interface BookingService {
    public function createBooking(Request $request);
    public function updateBooking(Request $request);
    public function getAllBookings(Request $request);
    public function getBooking(Request $request, $id);
    public function verifyBooking(Request $request);
    public function updateBookingData(Request $request, $id);
    public function generateETicket($id);
    public function generateSubBookings($id);
    public function updateStatus(Request $request, $id);
}