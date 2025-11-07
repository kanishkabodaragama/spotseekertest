<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\EventService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    use ApiResponse;

    private EventService $eventRepository;

    public function __construct(EventService $eventRepository)
    {
        $this->eventRepository = $eventRepository;
    }

    public function index(Request $request)
    {
        $response = $this->eventRepository->getAllEvents($request);
        return $this->generateResponse($response);
    }

    public function store(Request $request)
    {
        $response = $this->eventRepository->createEvent($request);
        return $this->generateResponse($response);
    }

    public function show($id)
    {
        $response = $this->eventRepository->getEvent($id);
        return $this->generateResponse($response);
    }

    public function destroy($id)
    {
        $response = $this->eventRepository->removeEvent($id);
        return $this->generateResponse($response);
    }

    public function updateEvent(Request $request, $id)
    {
        $response = $this->eventRepository->updateEvent($request, $id);
        return $this->generateResponse($response);
    }

    public function getEventStats($id)
    {
        $response = $this->eventRepository->getEventStats($id);
        return $this->generateResponse($response);
    }

    public function getManagerEvents(Request $request)
    {
        $response = $this->eventRepository->getManagerEvents($request);
        return $this->generateResponse($response);
    }

    public function getEventByUID($id)
    {
        $response = $this->eventRepository->getEventByUID($id);
        return $this->generateResponse($response);
    }

    public function sendInvitations(Request $request)
    {
        $response = $this->eventRepository->sendInvitations($request);
        return $this->generateResponse($response);
    }

    public function getInvitation($id)
    {
        $response = $this->eventRepository->getInvitation($id);
        return $this->generateResponse($response);
    }

    public function getInvitations(Request $request, $id)
    {
        $response = $this->eventRepository->getInvitations($request, $id);
        return $this->generateResponse($response);
    }

    public function invitationRSVP(Request $request)
    {
        $response = $this->eventRepository->invitationRSVP($request);
        return $this->generateResponse($response);
    }

    public function getManagerEventById($id) {
        $response = $this->eventRepository->getManagerEventById($id);
        return $this->generateResponse($response);
    }

    public function updateManagerEvent(Request $request, $id) {
        $response = $this->eventRepository->updateManagerEvent($request, $id);
        return $this->generateResponse($response);
    }
}
