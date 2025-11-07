<?php

namespace App\Repositories;

use App\Models\TicketPackage;
use App\Traits\ApiResponse;
use App\Services\TicketPackageService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TicketPackageRepository implements TicketPackageService
{

    use ApiResponse;

    public function createTicketPackage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required',
                'desc' => 'required',
                'price' => 'required',
                'seating_range' => 'required',
            ]);

            if ($validator->fails()) {
                return $this->generateResponse("validation failed", '', 'Failed', 401, $validator->errors());
            }

            $newVenue = TicketPackage::create([
                'name' => $request->input('name'),
                'desc' => $request->input('desc'),
                'price' => $request->input('price'),
                'seating_range' => json_encode($request->input('seating_range')),
                'tot_tickets' => $request->input('tot_tickets'),
                'aval_tickets' => $request->input('tot_tickets')
            ]);
            return $this->generateResponse('ticket package created successfully', $newVenue->serialize());
        } catch (Exception $e) {
            return $this->generateResponse($e->getMessage(), '', 'Failed', 500);
        }
    }
}
