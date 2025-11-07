<?php 

namespace App\Services;

use Illuminate\Http\Request;

interface EventService {
    public function createEvent(Request $request);
    public function getAllEvents(Request $request);
    public function getEvent($id);
    public function getEventByUID($id);
    public function removeEvent($id);
    public function updateEvent(Request $request, $id);
    public function getEventStats($id);
    public function getManagerEvents(Request $request);
    public function sendInvitations(Request $request);
    public function getInvitation($id);
    public function getInvitations($request, $id);
    public function getManagerEventById($id);
    public function updateManagerEvent(Request $request, $id);
    public function invitationRSVP(Request $request);
    public function addPixelCodes(Request $request);
    public function getPixelsByEvent(Request $request);
}