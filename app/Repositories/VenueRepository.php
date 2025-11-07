<?php

namespace App\Repositories;

use App\Traits\ApiResponse;
use App\Models\Venue;
use App\Services\VenueService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class VenueRepository implements VenueService
{

    use ApiResponse;

    public function createVenue(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'location_url' => 'required|string'
            ]);

            if ($validator->fails()) {
                return (object) [
                    "message" => 'validation failed',
                    "status" => false,
                    "errors" => $validator->messages()->toArray(),
                    "code" => 422
                ];
            }

            /*$seat_map_img_filename = null;

            if ($request->file('seat_map')) {
                $file = $request->file('seat_map');
                $seat_map_img_filename = date('YmdHi') . "-" . str_replace(' ', '-', strtolower(($request->input('name')))) . "-seat-map." . $request->file('seat_map')->extension();
                //$file->move(public_path('venues'), $seat_map_img_filename);
                try {
                    $path = Storage::disk('s3')->putFileAs(env('AWS_BUCKET_PATH') . "/seat-maps", $file, $seat_map_img_filename);
                    $seat_map_img_filename = Storage::disk('s3')->url($path);
                    //dd($path, $banner_img_filename);
                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $seat_map_img_filename);
                }
                catch(Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }*/

            $newVenue = Venue::create([
                'name' => $request->input('name'),
                'location_url' => $request->input('location_url'),
                'seating_capacity' => 1000
            ]);

            $newVenue->seat_map = $this->generateSeatMapId($newVenue->id);
            $newVenue->save();

            return (object) [
                "message" => 'venue created successfully',
                "status" => true,
                "data" => $newVenue->serialize()
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getAllVenues()
    {
        try {

            $venues = Venue::all();

            $venue_arr = [];

            foreach($venues as $venue) {
                array_push($venue_arr, $venue->serialize());
            }

            return (object) [
                "message" => 'venues retreived successfully',
                "status" => true,
                "data" => $venue_arr,
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getVenue($id) {
        try {
            $venue = Venue::findOrFail($id);

            return (object) [
                "message" => 'venue retrieved successfully',
                "status" => true,
                "data" => $venue
            ];
        } catch(Exception $e) {
            return (object) [
                "message" => 'venue not found',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404
            ];
        }

    }

    public function removeVenue($id)
    {
        try {

            $venue = Venue::withCount('events')->findOrFail($id);

            if ($venue->events_count > 0) {
                return (object) [
                    "message" => 'venue cannot delete, related event exist',
                    "status" => false,
                    "code" => 400
                ];
            }

            $venue->delete();

            return (object) [
                "message" => 'venue deleted successfully',
                "status" => true,
                "data" => $venue
            ];
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'venue cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500
            ];
        }
    }

    public function updateVenue(Request $request, $id)
    {
        try {

            $venue = Venue::findOrFail($id);
            $venue->name = $request->input('name');
            $venue->location_url = $request->input('location_url');

            /*$seat_map_img_filename = null;
            if ($request->file('seat_map')) {
                $file = $request->file('seat_map');
                $seat_map_img_filename = date('YmdHi') . "-" . str_replace(' ', '-', strtolower(($request->input('name')))) . "-seat-map." . $request->file('seat_map')->extension();
                //$file->move(public_path('venues'), $seat_map_img_filename);
                try {
                    $path = Storage::disk('s3')->putFileAs(env('AWS_BUCKET_PATH') . "/seat-maps", $file, $seat_map_img_filename);
                    $seat_map_img_filename = Storage::disk('s3')->url($path);
                    //dd($path, $banner_img_filename);
                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $seat_map_img_filename);
                }
                catch(Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }*/

            $venue->seat_map = $this->generateSeatMapId($venue->id);

            $venue->save();

            return (object) [
                "message" => 'venue updated successfully',
                "status" => true,
                "data" => $venue
            ];
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'venue cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500
            ];
        }
    }

    private function generateSeatMapId($venue_id)
    {
        $venue = Venue::findOrFail($venue_id);

        $venue_words = explode(" ", $venue->name);
        $venue_acronym = "";

        for ($i = 0; $i < sizeof($venue_words); $i++) {
            if(preg_match("/^[a-zA-Z]+$/", $venue_words[$i]) == 1) {
                if ($i != 3) {
                    $venue_acronym .= mb_substr($venue_words[$i], 0, 1);
                } else {
                    break;
                }
            }            
        }

        return $venue_acronym;
    }
}
