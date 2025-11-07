<?php

namespace App\Http\Controllers\api;

use App\Enums\EventStatus;
use App\Enums\SubscriptionType;
use App\Http\Controllers\Controller;
use App\Jobs\SendSMSJob;
use App\Models\Event;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use App\Traits\ApiResponse;
use App\Traits\NotifyUtils;
use Exception;
use Fouladgar\MobileVerification\Tokens\TokenBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    use ApiResponse, NotifyUtils;

    public function subscribe(Request $request)
    {
        DB::beginTransaction();
        try {
            $validatedData = $request->validate([
                'mobile_no' => ['required', 'string', 'min:10', 'max:15', 'regex:/^\d+$/'],
                'event_id' => ['nullable', 'string', 'exists:events,uid'],
                'type' => ['required', 'string', function ($attribute, $value, $fail) {
                    try {
                        SubscriptionType::from($value);
                    } catch (\ValueError $e) {
                        $fail('The selected ' . $attribute . ' is invalid.');
                    }
                }],
            ]);

            if (User::where('phone_no', $request->input('mobile_no'))->where('id', '!=', $request->user()->id)->exists()) {
                throw new Exception("This phone number is already in use. Please provide a different phone number.", 400);
            }

            if (isset($validatedData['event_id'])) {
                $event = Event::where('uid', $validatedData['event_id'])
                    ->whereIn('status', [EventStatus::PENDING->value, EventStatus::ONGOING->value, EventStatus::PRESALE->value, EventStatus::POSTPONED->value])
                    ->first();

                if (!$event) {
                    return $this->errorResponse('Event not found', [], 400);
                }
            }

            $existingSubscriptionQuery = Subscription::where('mobile_no', $validatedData['mobile_no']);

            $user = User::findOrFail($request->user()->id);
            if ($user->phone_no !== $validatedData['mobile_no']) {
                $user->phone_no = $validatedData['mobile_no'];
                $user->mobile_verified_at = null;
                $user->save();
            }

            if (isset($validatedData['event_id'])) {
                $existingSubscriptionQuery->where('event_id', $event->id);
            }

            if (isset($validatedData['type'])) {
                $existingSubscriptionQuery->where('type', $validatedData['type']);
            }

            $existingSubscription = $existingSubscriptionQuery->first();

            if ($existingSubscription && $user->hasVerifiedMobile()) {
                return $this->errorResponse('Mobile number is already subscribed.', [], 400);
            } else if ($existingSubscription && !$user->hasVerifiedMobile()) {
                $broker = resolve(TokenBroker::class);
                $broker->sendToken($user);

                DB::commit();

                $existingSubscription->mobile_verified = $user->hasVerifiedMobile();

                return $this->errorResponse('Mobile number is already subscribed.', $existingSubscription, 400);
            }

            // Create a new subscription
            $subscription = new Subscription();
            $subscription->user_id = $request->user()->id;
            $subscription->mobile_no = $validatedData['mobile_no'];
            $subscription->event_id = null;

            if (isset($event)) {
                $subscription->event_id = $event->id;
            }

            if (isset($validatedData['type'])) {
                $subscription->type = $validatedData['type'];
            } else {
                $subscription->type = SubscriptionType::ALL->value;
            }

            $subscription->save();

            if (!$user->hasVerifiedMobile()) {
                $broker = resolve(TokenBroker::class);
                $broker->sendToken($user);
            }

            DB::commit();

            $subscription->mobile_verified = $user->hasVerifiedMobile();

            return $this->successResponse('Successfully subscribed!', $subscription);
        } catch (Exception $e) {
            DB::rollBack();
            Log::info($e);
            return $this->errorResponse($e->getMessage(), [], 400);
        }
    }

    public function sendBulkSMS(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'event_id' => ['nullable', 'string', 'exists:events,uid'],
                'type' => ['required', 'string', function ($attribute, $value, $fail) {
                    try {
                        SubscriptionType::from($value);
                    } catch (\ValueError $e) {
                        $fail('The selected ' . $attribute . ' is invalid.');
                    }
                }],
                'message' => ['nullable', 'string'],
                'schedule_on' => ['nullable', 'string'],
                'schedule_at' => ['nullable', 'string'],
            ]);

            if (isset($validatedData['event_id'])) {
                $event = Event::where('uid', $validatedData['event_id'])
                    ->whereIn('status', [EventStatus::PENDING->value, EventStatus::ONGOING->value, EventStatus::PRESALE->value, EventStatus::POSTPONED->value])
                    ->first();

                if (!$event) {
                    return $this->errorResponse('Event not found', [], 400);
                }
            }

            $subscriptionQuery = Subscription::where('type', $validatedData['type']);

            if (isset($validatedData['event_id'])) {
                $subscriptionQuery->where('event_id', $validatedData['event_id']);
            }

            $subs = $subscriptionQuery->get();

            if ($validatedData['type'] == SubscriptionType::ALL->value) {
                if (isset($validatedData['message'])) {
                    $details['message'] = $this->generateCustomSMS("", $validatedData['message'], config('app.name'));
                } else {
                    $details['message'] = $this->generateGeneralSMS();
                }
            }

            foreach ($subs as $sub) {
                $details['cust_mobile'] = $sub->mobile_no;
                dispatch(new SendSMSJob($details))->delay(1);
            }

            return $this->successResponse("bulk sms queued successfully", []);
        } catch (Exception $e) {
            Log::error($e);
            return $this->errorResponse($e->getMessage(), [], 400);
        }
    }

    public function schedule(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validate request data
            $validatedData = $request->validate([
                'message' => 'required|string',
                'scheduled_at' => 'required|date|after:now',
                'events' => 'nullable|array',
                'events.*' => 'exists:events,id'
            ]);

            $eventIds = $request->input('events');
            $phone_nos = collect(); // Default empty collection

            if (!empty($eventIds)) {
                // Fetch phone numbers for specific events
                $user_phone_nos = User::select('phone_no as mobile_no')
                    ->whereHas('orders', function ($query) use ($eventIds) {
                        $query->whereIn('event_id', $eventIds);
                    })
                    ->whereNotNull('phone_no')
                    ->distinct();

                $sub_phone_nos = Subscription::select('mobile_no')
                    ->whereIn('event_id', $eventIds);

                $phone_nos = $user_phone_nos->union($sub_phone_nos->toBase())->distinct()->get();
            } else {
                // Fetch all phone numbers if no events specified
                $phone_nos = User::select('phone_no as mobile_no')
                    ->whereNotNull('phone_no')
                    ->distinct()
                    ->get();
            }

            if ($phone_nos->isEmpty()) {
                return $this->errorResponse("No phone numbers found.", [], 400);
            }

            // Prepare notifications
            $notifications = [];
            $target = count($eventIds ?? []) > 0 ? $eventIds : ['all'];

            foreach ($phone_nos as $phone_no_obj) {
                $notifications[] = [
                    'channel' => 'mobile',
                    'channel_value' => $phone_no_obj->mobile_no,
                    'target' => json_encode($target),
                    'message' => $validatedData['message'],
                    'scheduled_at' => $validatedData['scheduled_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert notifications
            Notification::insert($notifications);

            DB::commit();

            return $this->successResponse("SMS scheduled successfully!", []);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return $this->errorResponse(
                "Failed to schedule SMS. Please try again.",
                [],
                $e->getCode() ?: 400
            );
        }
    }


    public function getNotifications(Request $request)
    {
        try {
            $notifications = Notification::orderBy('scheduled_at', 'desc')->get();

            $formatted_notifications = $notifications->map(function ($notif) {
                // Decode target and check for errors
                $decodedTarget = json_decode($notif->target, true);

                if (is_null($decodedTarget) || !is_array($decodedTarget)) {
                    $notif->target = 'Invalid target data';
                } elseif ($decodedTarget === ['all']) {
                    $notif->target = 'All Users';
                } else {
                    // Fetch event names from the event IDs
                    $eventNames = Event::whereIn('id', $decodedTarget)->pluck('name')->toArray();

                    // If event names are found, format them as a string
                    if (count($eventNames) > 0) {
                        $notif->target = implode(', ', $eventNames);
                    } else {
                        $notif->target = 'No events found';
                    }
                }

                return $notif;
            });

            return $this->successResponse("Notifications retrieved successfully", $formatted_notifications);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), [], $e->getCode() ?? 400);
        }
    }


    public function getNotification($id)
    {
        try {
            $notification = Notification::findOrFail($id);
            return $this->successResponse("Notification retrieved successfully", $notification);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), [], $e->getCode() ?? 400);
        }
    }

    public function removeNotification($id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $notification->delete();
            return $this->successResponse("Notification retrieved successfully", $notification);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), [], $e->getCode() ?? 400);
        }
    }

    public function updateNotification(Request $request, $id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $notification->delete();
            return $this->successResponse("Notification retrieved successfully", $notification);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), [], $e->getCode() ?? 400);
        }
    }
}
