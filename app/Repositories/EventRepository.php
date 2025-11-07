<?php

namespace App\Repositories;

use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\Roles;
use App\Jobs\SendEmailJob;
use App\Jobs\SendEventInvitationJob;
use App\Jobs\SendBatchEventInvitationJob;
use App\Jobs\UploadTicketJob;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventInvitation;
use App\Models\Promotion;
use App\Models\SubTicket;
use App\Services\EventService;
use App\Models\TicketPackage;
use App\Models\TicketSale;
use App\Models\TicketSalePackage;
use App\Models\User;
use App\Traits\BookingUtils;
use App\Traits\CheckManager;
use App\Traits\QrCodeGenerator;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class EventRepository implements EventService
{
    use CheckManager, BookingUtils, QrCodeGenerator;

    public function createEvent(Request $request)
    {
        DB::beginTransaction();

        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'venue' => 'required|integer|exists:venues,id',
                'organizer' => 'required|string',
                'description' => 'required|string',
                'manager' => 'required|integer',
                'start_date' => 'required|string',
                'end_date' => 'required|string',
                'free_seating' => 'required',
                'banner_img' => 'required',
                'thumbnail_img' => 'required',
                'invoice' => 'required',
                'currency' => 'required|string',
                'type' => 'required|string',
                'sub_type' => 'required|string',
                'payment_gateways' => 'required'
            ]);

            if ($validator->fails()) {
                return (object) [
                    "message" => 'validation failed',
                    "status" => false,
                    "errors" => $validator->messages()->toArray(),
                    "code" => 422,
                    "data" => []
                ];
            }

            $banner_img_filename = null;
            $thumbnail_img_filename = null;

            if ($request->file('banner_img')) {
                $banner_img_file = $request->file('banner_img');
                $banner_img_filename = date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-banner." . $request->file('banner_img')->extension();
                //$banner_img_file->move(public_path('events'), $banner_img_filename);
                try {
                    $path = Storage::disk('s3')->putFileAs(env('AWS_BUCKET_PATH') . "/events", $banner_img_file, $banner_img_filename);
                    $banner_img_filename = Storage::disk('s3')->url($path);
                    //dd($path, $banner_img_filename);
                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $banner_img_filename);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            if ($request->file('thumbnail_img')) {
                $thumbnail_img_file = $request->file('thumbnail_img');
                $thumbnail_img_filename = date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-thumbnail." . $request->file('thumbnail_img')->extension();
                //$thumbnail_img_file->move(public_path('events'), $thumbnail_img_filename);
                try {
                    $path = Storage::disk('s3')->putFileAs(env('AWS_BUCKET_PATH') . "/events", $thumbnail_img_file, $thumbnail_img_filename);
                    $thumbnail_img_filename = Storage::disk('s3')->url($path);

                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $thumbnail_img_filename);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            $event_words = preg_split("/[\s,_-]+/", $request->input('name'));

            $free_seating = $request->input('free_seating') === "true";

            $newEvent = Event::create([
                'uid' => $this->generateEventUID(),
                'name' => implode(" ", $event_words),
                'json_desc' => $request->input('description'),
                'free_seating' => $free_seating,
                'venue_id' => $request->input('venue'),
                'organizer' => $request->input('organizer'),
                'manager' => $request->input('manager'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'type' => $request->input('type'),
                'sub_type' => $request->input('sub_type'),
                'banner_img' => $banner_img_filename,
                'thumbnail_img' => $thumbnail_img_filename,
                'featured' => $request->input('featured') === "true" ? 1 : 0,
                'handling_cost' => $request->input('handling_cost'),
                'handling_cost_perc' => $request->input('handling_cost_perc') === "true" ? 1 : 0,
                'currency' => $request->input('currency'),
                'invitation_feature' => $request->input('invitation_feature') === "true" ? 1 : 0,
                'invitation_count' => $request->input('invitation_count'),
                'message' => $request->input('sold_out_msg'),
                'trailer_url' => $request->input('trailer_url'),
                'addons_feature' => $request->input('addons_feature') === "true" ? 1 : 0
            ]);



            $packages = json_decode($request->input('invoice'), true);

            foreach ($packages as $package) {
                $validator = Validator::make($package, [
                    'packageName' => 'required',
                    'packagePrice' => 'required'
                ]);

                if ($validator->fails()) {
                    return (object) [
                        "message" => 'validation failed',
                        "status" => false,
                        "errors" => $validator->messages()->toArray(),
                        "code" => 422,
                        "data" => []
                    ];
                }

                $alloc_seats_arr = [];
                $avail_seats_arr = [];
                $res_seats_arr = [];

                if (!$free_seating) {
                    $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                    $avail_seats_arr = explode(",", $package['packageAvailSeats']);
                    $res_seats_arr = explode(",", $package['packageResSeats']);
                }

                $newTicketPackage = TicketPackage::create([
                    'event_id' => $newEvent->id,
                    'name' => $package['packageName'],
                    'desc' => $package['packageDesc'],
                    'price' => $package['packagePrice'],
                    'seating_range' => $alloc_seats_arr,
                    'tot_tickets' => $package['packageQty'],
                    'aval_tickets' => $package['packageAvailQty'],
                    'reserved_seats' => $res_seats_arr,
                    'available_seats' => $avail_seats_arr,
                    'free_seating' => $package['packageFreeSeating'] === true ? 1 : 0,
                    'active' =>  $package['active'] === true ? 1 : 0,
                    'sold_out' =>  $package['sold_out'] === true ? 1 : 0,
                ]);

                $isPromoEnabled = $package["promotions"];

                if ($isPromoEnabled) {
                    foreach ($package['promotion'] as $promo) {
                        $newPackagePromotion = Promotion::create([
                            'event_id' => $newEvent->id,
                            'package_id' => $newTicketPackage->id,
                            'coupon_code' => $promo["promoCode"],
                            'discount_amount' => $promo["discAmount"],
                            'percentage' => $promo["discAmtIsPercentage"],
                            'min_tickets' => $promo["minTickets"],
                            'min_amount' => $promo["minAmount"],
                            'max_tickets' => $promo["maxTickets"],
                            'max_amount' => $promo["maxAmount"],
                            'start_date' => $promo["startDateTime"],
                            'end_date' => $promo["endDateTime"],
                            'per_ticket' => $promo["isPerTicket"],
                            'auto_apply' => $promo["isAutoApply"],
                            'redeems' => $promo["redeems"]
                        ]);
                    }
                }
            }

            if ($request->input('invitation_feature') === "true") {
                $invitation_packages = json_decode($request->input('invitation_packages'), true);

                foreach ($invitation_packages as $package) {
                    $validator = Validator::make($package, [
                        'packageName' => 'required',
                        'packageDesc' => 'required',
                        'packageAvailQty' => 'required'
                    ]);

                    if ($validator->fails()) {
                        return (object) [
                            "message" => 'validation failed',
                            "status" => false,
                            "errors" => $validator->messages()->toArray(),
                            "code" => 422,
                            "data" => []
                        ];
                    }

                    $alloc_seats_arr = [];

                    if (!$free_seating) {
                        $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                    }

                    $newTicketPackage = TicketPackage::create([
                        'name' => $package['packageName'],
                        'event_id' => $newEvent->id,
                        'desc' => $package['packageDesc'],
                        'price' => 0,
                        'seating_range' => $alloc_seats_arr,
                        'tot_tickets' => $package['packageAvailQty'],
                        'aval_tickets' => $package['packageAvailQty'],
                        'reserved_seats' => [],
                        'available_seats' => [],
                        'free_seating' => $package['packageFreeSeating'] === true ? 1 : 0,
                        'active' => $package['active'] === true ? 1 : 0,
                        'private' => 1
                    ]);
                }
            }

            if ($request->input('addons_feature') === "true") {
                $addons = json_decode($request->input('addons'), true);

                $allDeleted = count($addons) === count(array_filter($addons, function ($addon) {
                    return isset($addon['deleted']) && $addon['deleted'] === true;
                }));

                if ($allDeleted) {
                    $newEvent->addons_feature = false;
                    $newEvent->save();
                }

                $addonImages = $request->file('addonImage');

                foreach ($addons as $index => $addon) {
                    $validator = Validator::make($addon, [
                        'addonName' => 'required',
                        'addonPrice' => 'required',
                        'addonCategory' => 'required'
                    ]);

                    if ($validator->fails()) {
                        return (object) [
                            "message" => 'validation failed',
                            "status" => false,
                            "errors" => $validator->messages()->toArray(),
                            "code" => 422,
                            "data" => []
                        ];
                    }

                    Log::debug('Addons update started');

                    if (isset($addon['addonId']) && !$addon['deleted']) {
                        Log::debug('Addon update started: ' . $addon['addonId']);
                        $event_addon = EventAddon::findOrFail($addon['addonId']);
                        $event_addon->name = $addon['addonName'];
                        $event_addon->price = $addon['addonPrice'];
                        $event_addon->category = $addon['addonCategory'];

                        if (isset($addonImages[$index]) && $addonImages[$index]) {
                            // Upload the image to S3
                            $addon_img_file = $addonImages[$index]; // Assuming you already have this file in the $addonImage array
                            try {
                                $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events/addons", $addon_img_file);
                                $addon_img_filename = Storage::disk('s3')->url($path);

                                Log::debug('S3 file upload successful: ' . $addon_img_filename);

                                // Store the image URL in the addon model
                                $event_addon->image_url = $addon_img_filename;
                            } catch (Exception $err) {
                                Log::debug('S3 file upload error: ' . $err->getMessage());
                            }
                        }

                        $event_addon->save();
                    } else if ($addon['deleted']) {
                        $event_addon = EventAddon::findOrFail($addon['addonId']);
                        $event_addon->delete();
                    } else {

                        $newTicketPackage = EventAddon::create([
                            'event_id' => $newEvent->id,
                            'name' => $addon['addonName'],
                            'price' => $addon['addonPrice'],
                            'category' => $addon['addonCategory'],
                        ]);

                        if (isset($addonImages[$index]) && $addonImages[$index]) {
                            // Upload the image to S3
                            $addon_img_file = $addonImages[$index]; // Assuming you already have this file in the $addonImage array
                            try {
                                $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events/addons", $addon_img_file);
                                $addon_img_filename = Storage::disk('s3')->url($path);

                                Log::debug('S3 file upload successful: ' . $addon_img_filename);

                                // Store the image URL in the addon model
                                $newTicketPackage->image_url = $addon_img_filename;
                            } catch (Exception $err) {
                                Log::debug('S3 file upload error: ' . $err->getMessage());
                            }
                        }

                        $newTicketPackage->save();
                    }
                }
            }


            $payment_gateways = json_decode($request->input('payment_gateways'), true);

            // Ensure $payment_gateways is an array before proceeding
            if (!is_array($payment_gateways) || (count($payment_gateways) === 1 && empty($payment_gateways[0]['id']))) {
                throw new Exception('Please select at least one payment gateway to proceed', 422);
            }

            foreach ($payment_gateways as $payment_gateway) {
                if (!empty($payment_gateway["id"])) {
                    $validator = Validator::make($payment_gateway, [
                        'id' => 'required|string|exists:payment_gateways,id',
                    ]);

                    if ($validator->fails()) {
                        return (object) [
                            "message" => 'validation failed',
                            "status" => false,
                            "errors" => $validator->messages()->toArray(),
                            "code" => 422,
                            "data" => []
                        ];
                    }

                    Log::debug('Payment gateways update started');

                    $newEvent->payment_gateways()->attach($payment_gateway['id']);
                    Log::info("Payment gateway {$payment_gateway['id']} added to event {$newEvent->id}");
                }
            }

            if ($request->filled('analytics_ids')) {
                // Decode the input JSON string into an array
                $inputAnalytics = json_decode($request->input('analytics_ids'), true) ?? [];

                // Initialize a new array for analytics IDs
                $analyticsIds = [];

                // Only include the platforms sent in the request
                foreach ($inputAnalytics as $item) {
                    if (isset($item['platform'], $item['pixel_code'])) {
                        $analyticsIds[$item['platform']] = $item['pixel_code'];
                    }
                }

                // Save back to the event (Laravel will handle JSON storage automatically)
                $newEvent->analytics_ids = $analyticsIds;
                $newEvent->save();

            }

            DB::commit();

            return (object) [
                "message" => 'event created successfully',
                "status" => true,
                "data" => $newEvent->serialize()
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getAllEvents(Request $request)
    {
        try {
            $query = Event::with('venue', 'managerr', 'payment_gateways');

            // Filter by status based on user role or allowed statuses
            if (Auth::user() && Auth::user()->hasRole('Admin')) {
                $query->when($request->input('status'), function ($q, $statuses) {
                    if (is_array($statuses) && count($statuses) > 0) {
                        $q->whereIn('status', $statuses);
                    }
                });
            } else {
                $allowedStatuses = [
                    EventStatus::PENDING->value,
                    EventStatus::ONGOING->value,
                    EventStatus::SOLDOUT->value,
                    EventStatus::CLOSED->value,
                    EventStatus::POSTPONED->value
                ];

                $request->validate([
                    'status' => [
                        'required',
                        'array',
                        Rule::in($allowedStatuses),
                    ],
                ]);

                $query->when($request->input('status'), function ($q, $statuses) use ($allowedStatuses) {
                    $statuses = is_array($statuses) ? array_intersect($statuses, $allowedStatuses) : [];

                    if (count($statuses) > 0) {
                        $q->whereIn('status', $statuses);
                    } else {
                        return (object)[
                            "message" => 'client error',
                            "status" => false,
                            "errors" => "",
                            "code" => 400
                        ];
                    }
                });
            }

            // Apply other filters
            $query->when($request->input('limit'), function ($q, $limit) {
                $q->limit($limit);
            });

            $query->when($request->input('name'), function ($q, $name) {
                $q->where('name', 'like', '%' . $name . '%');
            });

            $query->when($request->input('type'), function ($q, $type) {
                $q->where('type', $type);
            });

            $query->when($request->input('featured'), function ($q, $featured) {
                $q->where('featured', $featured == true ? 1 : 0);
            });

            if ($request->input('start_date') && $request->input('end_date')) {
                $query->whereDate('start_date', '>=', date('Y-m-d', strtotime($request->input('start_date'))))
                      ->whereDate('start_date', '<=', date('Y-m-d', strtotime($request->input('end_date'))));
            }
    
            // Apply price range filter based on EventPackage's price field
            if ($request->input('price_min') && $request->input('price_max')) {
                $query->whereHas('ticket_packages', function ($q) use ($request) {
                    $q->whereBetween('price', [$request->input('price_min'), $request->input('price_max')]);
                });
            }

            // Apply search filter, ensuring it doesn't override other filters
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->whereRelation('venue', 'name', 'like', '%' . $search . '%')
                        ->orWhereRelation('managerr', 'name', 'like', '%' . $search . '%')
                        ->orWhereRelation('managerr', 'email', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%');
                });
            }

            // Handle pagination and custom ordering for admins
            if (Auth::user() && Auth::user()->hasRole('Admin') && $request->input('per_page') != null) {
                $perPage = $request->input('per_page', 10);
                $page = $request->input('page', 1);

                $statusOrder = [
                    'pending' => 1,
                    'ongoing' => 2,
                    'postponed' => 3,
                    'closed' => 4,
                    'soldout' => 5,
                    'complete' => 6,
                ];

                $statusOrderSql = 'CASE';
                foreach ($statusOrder as $status => $order) {
                    $statusOrderSql .= " WHEN status = '$status' THEN $order";
                }
                $statusOrderSql .= ' ELSE 999 END';

                $eventsQuery = clone $query;

                $events = $eventsQuery->orderBy('featured', 'DESC')
                    ->orderByRaw($statusOrderSql)
                    ->orderBy('start_date', 'ASC')
                    ->paginate($perPage, ['*'], 'page', $page);

                $eventsSerialized = $events->getCollection()->map->serialize();

                $serializedEventsPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
                    $eventsSerialized,
                    $events->total(),
                    $events->perPage(),
                    $events->currentPage(),
                    ['path' => $request->url(), 'query' => $request->query()]
                );

                return (object)[
                    "message" => 'events retrieved successfully',
                    "status" => true,
                    "data" => $serializedEventsPaginator,
                ];
            } else {
                $events = $query->orderBy('featured', 'DESC')
                    ->orderBy('start_date', 'ASC')
                    ->get();
            }

            $event_arr = $events->map->serialize();

            return (object)[
                "message" => 'events retrieved successfully',
                "status" => true,
                "data" => $event_arr,
            ];
        } catch (Exception $e) {
            return (object)[
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => $e->getCode() == 0 ? 400 : $e->getCode()
            ];
        }
    }


    public function getEvent($id)
    {
        try {
            $event = Event::with('venue', 'ticket_packages', 'managerr', 'ticket_packages.promotions', 'addons', 'payment_gateways')->findOrFail($id);

            foreach ($event->ticket_packages as $package) {
                $pending_ticket_count = DB::table('ticket_sales')
                    ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                    ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                    ->where([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'pending'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->pluck('ticket_count')[0];
                $package['aval_tickets'] = $package->aval_tickets + (int)$pending_ticket_count;
                $package['sold_tickets'] = DB::table('ticket_sales')
                    ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                    ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                    ->where([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'complete'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->orWhere([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'verified'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->pluck('ticket_count')[0];
            }

            if (Auth::check() && Auth::user()->hasRole('Admin')) {

                foreach ($event->ticket_packages as $pack) {
                    $pack['sold_out'] = $pack->aval_tickets == 0 || $pack->sold_out;
                    $pack['promo'] = sizeof($pack->promotions) > 0 ? true : false;
                }

                return (object) [
                    "message" => 'event retreived successfully',
                    "status" => true,
                    "data" => $event,
                ];
            } else {
                if (!($event->status == 'pending' || $event->status == 'ongoing')) {
                    throw new ResourceNotFoundException("event not found");
                }
                return (object) [
                    "message" => 'event retreived successfully',
                    "status" => true,
                    "data" => $event->serialize(),
                ];
            }
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
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

    public function getEventByUID($id)
    {
        try {
            $event = Event::with('venue', 'ticket_packages', 'managerr', 'ticket_packages.promotions', 'payment_gateways')->where('uid', $id)->first();

            foreach ($event->ticket_packages as $package) {
                $pending_ticket_count = DB::table('ticket_sales')
                    ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                    ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                    ->where([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'pending'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->pluck('ticket_count')[0];
                $package['aval_tickets'] = $package->aval_tickets + (int)$pending_ticket_count;
                $package['sold_tickets'] = DB::table('ticket_sales')
                    ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                    ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                    ->where([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'complete'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->orWhere([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'verified'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->pluck('ticket_count')[0];
            }

            if (Auth::check() && Auth::user()->hasRole('Admin')) {

                foreach ($event->ticket_packages as $pack) {
                    $pack['sold_out'] = $pack->aval_tickets == 0 || !$pack->active;
                    $pack['promo'] = sizeof($pack->promotions) > 0 ? true : false;
                }

                return (object) [
                    "message" => 'event retreived successfully',
                    "status" => true,
                    "data" => $event,
                ];
            } else {
                if ($event->status == EventStatus::COMPLETE->value) {
                    throw new ResourceNotFoundException("event not found");
                }
                return (object) [
                    "message" => 'event retreived successfully',
                    "status" => true,
                    "data" => $event->serialize(),
                ];
            }
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
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

    public function getEventStats($id)
    {
        try {
            if (Auth::user() && !Auth::user()->hasRole('Admin')) {
                if (!$this->checkManager($id)) {
                    return (object) [
                        "message" => 'Requested event not found.',
                        "status" => false,
                        "code" => 401
                    ];
                }
            }

            $event = Event::with(['venue', 'ticket_packages' => function ($query) {
                $query->where('private', false);
            }, 'managerr', 'invitations'])
                ->withSum(
                    ['ticket_sales' => function ($query) {
                        $query->where('payment_status', PaymentStatus::COMPLETE->value)->orWhere('payment_status', PaymentStatus::VERIFIED->value)->orWhere('payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                    }],
                    'tot_amount'
                )
                ->withSum(
                    ['ticket_sales' => function ($query) {
                        $query->whereHas('packages.package', function ($query) {
                            $query->where('private', false);
                        })
                            ->where(function ($query) {
                                $query->where('payment_status', PaymentStatus::COMPLETE->value)
                                    ->orWhere('payment_status', PaymentStatus::VERIFIED->value)
                                    ->orWhere('payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                            });
                    }],
                    'tot_ticket_count'
                )->findOrFail($id);

            $event_sales = $event->ticket_sales;
            $event_ticket_packages = $event->ticket_packages->filter(function ($package) {
                return !$package->private;
            });
            $event['ticket_packages'] = $event_ticket_packages;
            $tot_sale = 0;

            $tot_verified_sub_booking = 0;
            $tot_completed_sub_booking = 0;
            if (Carbon::parse($event->created_at)->lt(Carbon::parse('2024-05-07'))) {
                foreach ($event_ticket_packages as $t_package) {
                    $package_id = $t_package->id;

                    $ticket_count = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'complete'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->orWhere([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'verified'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->pluck('ticket_count');



                    $verified_ticket_count = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'verified'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->orWhere([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'partially verified'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->pluck('ticket_count');

                    $complete_ticket_count = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'complete'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->pluck('ticket_count');

                    $failed_ticket_count = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'failed'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->pluck('ticket_count');

                    $cancelled_ticket_count = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where([
                            ['ticket_sales.event_id', $id],
                            ['ticket_sales.payment_status', 'cancelled'],
                            ['ticket_sale_packages.package_id', $package_id],
                            ['ticket_sales.deleted_at', null]
                        ])
                        ->pluck('ticket_count');

                    $sales_amount = ($verified_ticket_count[0] + $complete_ticket_count[0]) * (float)$t_package->price;
                    $tot_sale += $sales_amount;

                    $sales_prec =  ((($t_package->tot_tickets - $t_package->res_tickets) - $t_package->aval_tickets) / ($t_package->tot_tickets - $t_package->res_tickets)) * 100;

                    $t_package['sold_ticket_counts'] = $ticket_count[0];
                    $t_package['verified_ticket_count'] = $verified_ticket_count[0] ? $verified_ticket_count[0] : 0;
                    $t_package['complete_ticket_count'] = $complete_ticket_count[0];
                    $t_package['failed_ticket_count'] = $failed_ticket_count[0];
                    $t_package['cancelled_ticket_count'] = $cancelled_ticket_count[0];
                    $t_package['sales_prec'] = $sales_prec;
                    $t_package['sales_amount'] = $sales_amount;
                }

                $verified_bookings = TicketSale::select(DB::raw("COUNT(*) as verified_bookings"))
                    ->where([
                        ['payment_status', PaymentStatus::VERIFIED->value],
                        ['event_id', $id]
                    ])
                    ->orWhere([
                        ['payment_status', PaymentStatus::PARTIALLY_VERIFIED->value],
                        ['event_id', $id]
                    ])
                    ->pluck('verified_bookings');

                $completed_bookings = TicketSale::select(DB::raw("COUNT(*) as completed_bookings"))
                    ->where([
                        ['payment_status', PaymentStatus::COMPLETE->value],
                        ['event_id', $id]
                    ])
                    ->pluck('completed_bookings');

                $failed_bookings = TicketSale::select(DB::raw("COUNT(*) as failed_bookings"))
                    ->where([
                        ['payment_status', PaymentStatus::FAILED->value],
                        ['event_id', $id]
                    ])
                    ->pluck('failed_bookings');

                $cancelled_bookings = TicketSale::select(DB::raw("COUNT(*) as cancelled_bookings"))
                    ->where([
                        ['payment_status', PaymentStatus::CANCELLED->value],
                        ['event_id', $id]
                    ])
                    ->pluck('cancelled_bookings');

                $tot_bookings = TicketSale::select(DB::raw("COUNT(*) as tot_bookings"))
                    ->where([
                        ['event_id', $id]
                    ])
                    ->pluck('tot_bookings');
                $event['verified_bookings'] = $verified_bookings[0];
                $event['completed_bookings'] = $completed_bookings[0];
                $event['failed_bookings'] = $failed_bookings[0];
                $event['cancelled_bookings'] = $cancelled_bookings[0];
                $event['tot_bookings'] = $tot_bookings[0];
            } else {
                foreach ($event_ticket_packages as $t_package) {

                    $package_id = $t_package->id;

                    $ticket_count = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                        ->where([
                            ['sub_tickets.booking_status', PaymentStatus::COMPLETE->value],
                            ['tsp.package_id', $package_id]
                        ])
                        ->orWhere([
                            ['sub_tickets.booking_status', PaymentStatus::VERIFIED->value],
                            ['tsp.package_id', $package_id]
                        ])
                        ->count();

                    $verified_ticket_count = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                        ->where([
                            ['tsp.package_id', $package_id],
                            ['sub_tickets.booking_status', PaymentStatus::VERIFIED->value],
                            ['sub_tickets.deleted_at', null]
                        ])
                        ->count();

                    $complete_ticket_count = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                        ->where([
                            ['tsp.package_id', $package_id],
                            ['sub_tickets.booking_status', PaymentStatus::COMPLETE->value],
                            ['sub_tickets.deleted_at', null]
                        ])
                        ->count();

                    $failed_ticket_count = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                        ->where([
                            ['tsp.package_id', $package_id],
                            ['sub_tickets.booking_status', PaymentStatus::FAILED->value],
                            ['sub_tickets.deleted_at', null]
                        ])
                        ->count();

                    $cancelled_ticket_count = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                        ->where([
                            ['tsp.package_id', $package_id],
                            ['sub_tickets.booking_status', PaymentStatus::CANCELLED->value],
                            ['sub_tickets.deleted_at', null]
                        ])
                        ->count();

                    $sales_amount = ($verified_ticket_count + $complete_ticket_count) * (float)$t_package->price;
                    $tot_sale += $sales_amount;
                    Log::info($verified_ticket_count . "," . $complete_ticket_count);

                    $sales_prec =  ((($t_package->tot_tickets - $t_package->res_tickets) - $t_package->aval_tickets) / ($t_package->tot_tickets - $t_package->res_tickets)) * 100;

                    $t_package['sold_ticket_counts'] = $ticket_count;
                    $t_package['verified_ticket_count'] = $verified_ticket_count ? $verified_ticket_count : 0;
                    $t_package['complete_ticket_count'] = $complete_ticket_count;
                    $t_package['failed_ticket_count'] = $failed_ticket_count;
                    $t_package['cancelled_ticket_count'] = $cancelled_ticket_count;
                    $t_package['sales_prec'] = $sales_prec;
                    $t_package['sales_amount'] = $sales_amount;
                }

                // Common base query with private check for ticket packages
                $baseQuery = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                    ->join('ticket_sales as ts', 'ts.id', '=', 'tsp.sale_id')
                    ->join('ticket_packages as tp', 'tp.id', '=', 'tsp.package_id')
                    ->where('ts.event_id', $id)
                    ->where('tp.private', false);

                // Verified bookings
                $verified_bookings = (clone $baseQuery)
                    ->where('sub_tickets.booking_status', PaymentStatus::VERIFIED->value)
                    ->count();

                // Completed bookings
                $completed_bookings = (clone $baseQuery)
                    ->where('sub_tickets.booking_status', PaymentStatus::COMPLETE->value)
                    ->count();

                // Failed bookings
                $failed_bookings = (clone $baseQuery)
                    ->where('sub_tickets.booking_status', PaymentStatus::FAILED->value)
                    ->count();

                // Cancelled bookings
                $cancelled_bookings = (clone $baseQuery)
                    ->where('sub_tickets.booking_status', PaymentStatus::CANCELLED->value)
                    ->count();

                // Total bookings (no need to filter by status)
                $tot_bookings = (clone $baseQuery)->count();

                $event['verified_bookings'] = $verified_bookings;
                $event['completed_bookings'] = $completed_bookings;
                $event['failed_bookings'] = $failed_bookings;
                $event['cancelled_bookings'] = $cancelled_bookings;
                $event['tot_bookings'] = $tot_bookings;

                $tot_verified_sub_booking = $verified_bookings;
                $tot_completed_sub_booking = $completed_bookings + $verified_bookings;
            }

            $sales = TicketSale::with('packages.promotion', 'packages.package')
                ->whereIn('booking_status', ['verified', 'complete', 'partially verified'])
                ->where('event_id', $id)
                ->get();

            $totalPromoAmount = 0;

            foreach ($sales as $sale) {
                foreach ($sale->packages as $package) {
                    if ($package->promotion) {

                        $promoAmount = 0;
                        if ($package->promotion->per_ticket) {
                            if ($package->promotion->percentage) {
                                $package_discount = $package->package->price * ($package->promotion->discount_amount / 100);
                                $promoAmount = $package_discount * $package->ticket_count;
                            } else {
                                $promoAmount = $package->promotion->discount_amount * $package->ticket_count;
                            }
                        } else {
                            if ($package->promotion->percentage) {
                                $promoAmount = $sale->tot_amount * ($package->promotion->discount_amount / 100);
                            } else {
                                $promoAmount = $package->promotion->discount_amount;
                            }
                        }

                        // Add promo amount to total promo amount
                        $totalPromoAmount += $promoAmount;
                    }
                }
            }

            if (Auth::check() && Auth::user()->hasRole('Admin')) {
                Log::info($event->ticket_sales_sum_tot_amount);
                Log::info($tot_sale);
                Log::info($totalPromoAmount);
                $event['tot_sale'] = number_format($event->ticket_sales_sum_tot_amount, 2);
                $event['tot_handling_cost'] = number_format($event->ticket_sales_sum_tot_amount - ($tot_sale - $totalPromoAmount), 2);
                $event['tot_discounts'] = $totalPromoAmount;
            } else {
                Log::info($event->ticket_sales_sum_tot_amount);
                Log::info($tot_sale);
                Log::info($totalPromoAmount);
                $event['tot_sale'] = $tot_sale - $totalPromoAmount;
                $event['tot_discounts'] = $totalPromoAmount;
            }

            $customer_count = DB::table('ticket_sales')
                ->select(DB::raw('count(DISTINCT(ticket_sales.user_id)) as customer_count'))
                ->where([
                    ['ticket_sales.event_id', $id],
                    ['ticket_sales.deleted_at', null]
                ])
                ->orderBy('ticket_sales.id')
                ->pluck('customer_count');

            $event['customer_count'] = $customer_count;

            $tickets_count_by_date = TicketSale::join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->join('ticket_packages', 'ticket_sale_packages.package_id', '=', 'ticket_packages.id')
                ->select(DB::raw("SUM(tot_ticket_count) as ticket_count"), DB::raw("DATE(ticket_sales.created_at) as date"))
                ->where(function ($query) use ($id) {
                    $query->where('ticket_sales.event_id', $id)
                        ->where('ticket_packages.private', false)
                        ->where(function ($q) {
                            $q->where('ticket_sales.payment_status', PaymentStatus::COMPLETE->value)
                                ->orWhere('ticket_sales.payment_status', PaymentStatus::VERIFIED->value)
                                ->orWhere('ticket_sales.payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                        });
                })
                ->groupBy('date')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [
                        $item->date => (int) $item->ticket_count
                    ];
                });


            $tickets_count_by_package_and_date = TicketSale::select(
                DB::raw("SUM(ticket_sale_packages.ticket_count) as ticket_count"),
                DB::raw("DATE(ticket_sales.created_at) as date"),
                'ticket_packages.name as package_id'
            )
                ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->join('ticket_packages', 'ticket_sale_packages.package_id', '=', 'ticket_packages.id')
                ->where('ticket_sales.event_id', $id)
                ->where(function ($query) {
                    $query->where('ticket_sales.payment_status', PaymentStatus::COMPLETE->value)
                        ->orWhere('ticket_sales.payment_status', PaymentStatus::VERIFIED->value)
                        ->orWhere('ticket_sales.payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                })
                ->where('ticket_packages.private', false)
                ->groupBy('date', 'ticket_packages.id') // Group by package name instead of ID
                ->get()
                ->groupBy('date')
                ->map(function ($group) {
                    return $group->mapWithKeys(function ($item) {
                        return [$item->package_id => $item->ticket_count];
                    });
                });


            $sales_by_date = DB::table('ticket_sales')
                ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->join('ticket_packages', 'ticket_sale_packages.package_id', '=', 'ticket_packages.id')
                ->selectRaw('SUM(DISTINCT ticket_sales.id) as unique_sales_count, SUM(tot_amount) as tickets_sale, DATE(ticket_sales.created_at) as date')
                ->where([
                    ['payment_status', PaymentStatus::COMPLETE->value],
                    ['ticket_sales.event_id', $id],
                    ['ticket_sales.deleted_at', null]
                ])
                ->orWhere([
                    ['payment_status', PaymentStatus::VERIFIED->value],
                    ['ticket_sales.event_id', $id],
                    ['ticket_sales.deleted_at', null]
                ])->orWhere([
                    ['payment_status', PaymentStatus::PARTIALLY_VERIFIED->value],
                    ['ticket_sales.event_id', $id],
                    ['ticket_sales.deleted_at', null]
                ])
                ->groupBy('date')
                ->pluck('tickets_sale', 'date');

            $sales_by_package_and_date = TicketSale::select(
                DB::raw("SUM(tot_amount) as tickets_sale"),
                DB::raw("DATE(ticket_sales.created_at) as date"),
                'ticket_packages.name as package_name'
            )
                ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->join('ticket_packages', 'ticket_sale_packages.package_id', '=', 'ticket_packages.id')
                ->where('ticket_sales.event_id', $id)
                ->where(function ($query) {
                    $query->where('ticket_sales.payment_status', PaymentStatus::COMPLETE->value)
                        ->orWhere('ticket_sales.payment_status', PaymentStatus::VERIFIED->value)
                        ->orWhere('ticket_sales.payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                })
                ->groupBy(DB::raw('DATE(ticket_sales.created_at)'), 'ticket_packages.name')  // Grouping by date and package name
                ->get()
                ->groupBy('date')
                ->map(function ($group) {
                    return $group->mapWithKeys(function ($item) {
                        return [$item->package_name => $item->tickets_sale];
                    });
                });


            $event['sales_by_date'] = $sales_by_date->isEmpty() ? null : $sales_by_date;
            $event['tickets_count_by_date'] = $tickets_count_by_date->isEmpty() ? null : $tickets_count_by_date;
            $event['tickets_count_by_package_and_date'] = $tickets_count_by_package_and_date->isEmpty() ? null : $this->fillMissingDates($tickets_count_by_package_and_date, $event->ticket_packages);
            $event['sales_by_package_and_date'] = $sales_by_package_and_date->isEmpty() ? null : $this->fillMissingDates($sales_by_package_and_date, $event->ticket_packages);

            $invitationStats = DB::table('event_invitations')
                ->select(
                    DB::raw('SUM(tickets_count) as total_invitations'),  // Total tickets count
                    DB::raw('SUM(CASE WHEN status = "accepted" THEN tickets_count ELSE 0 END) as total_accepted'),  // Sum for accepted
                    DB::raw('SUM(CASE WHEN status = "rejected" THEN tickets_count ELSE 0 END) as total_rejected'),  // Sum for rejected
                    DB::raw('SUM(CASE WHEN status NOT IN ("accepted", "rejected") THEN tickets_count ELSE 0 END) as total_no_response')  // Sum for no response
                )
                ->where('event_id', $event->id)
                ->first();

            $event['totalInvitations'] = $invitationStats->total_invitations;
            $event['totalAccepted'] = $invitationStats->total_accepted;
            $event['totalRejected'] = $invitationStats->total_rejected;
            $event['totalNoResponse'] = $invitationStats->total_no_response;

            $verifiedCounts = $this->getVerifiedCountsForEachInterval($id);

            // Get the current time and time 30 minutes ago
            $now = Carbon::now();
            $thirtyMinutesAgo = $now->subMinutes(30);

            // Filter the formatted results to include only the last 30 minutes
            $scanPerLastThirtyMins = $verifiedCounts->filter(function ($item) use ($thirtyMinutesAgo) {
                return Carbon::parse($item['time_slot'])->greaterThanOrEqualTo($thirtyMinutesAgo);
            })->sum('verified_count');

            $fansInside = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                ->join('ticket_sales as ts', 'ts.id', '=', 'tsp.sale_id')
                ->join('ticket_packages as tp', 'tp.id', '=', 'tsp.package_id')
                ->where('ts.event_id', $id)
                ->where('sub_tickets.booking_status', PaymentStatus::VERIFIED->value)
                ->count();

            $fansInPerc = 0;
            if (!($tot_completed_sub_booking == 0)) {
                $fansInPerc = ($fansInside / ($tot_completed_sub_booking + (int)$invitationStats->total_accepted)) * 100;
            }

            $event['liveStats'] = (object) [
                "fansInside" => $fansInside,
                "scanRate" => $verifiedCounts,
                "fansInPerc" => $fansInPerc,
                "scanPerLastThirtyMins" => $scanPerLastThirtyMins
            ];

            return (object) [
                "message" => 'event retreived successfully',
                "status" => true,
                "data" => $event,
            ];
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
            ];
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    private function fillMissingDates($data, $eventPackages)
    {
        $all_dates = $data->keys();
        $first_date = Carbon::parse($all_dates->first());
        $last_date = Carbon::parse($all_dates->last());

        $date_range = new Collection();
        $current_date = $first_date->copy();
        while ($current_date->lte($last_date)) {
            $date_range->push($current_date->toDateString());
            $current_date->addDay();
        }

        // Step 3: Fill in the missing dates with 0 sales
        $filled_sales = $date_range->mapWithKeys(function ($date) use ($data) {
            $sales_for_date = $data->get($date, collect());
            return [$date => $sales_for_date];
        });

        // Step 4: Ensure each package is represented for each date with 0 if missing
        $packages = $eventPackages->map(function ($package) {
            return $package->name;
        });
        $filled_sales = $filled_sales->map(function ($sales_for_date) use ($packages) {
            $sales_with_zeros = $packages->mapWithKeys(function ($package) use ($sales_for_date) {
                return [$package => $sales_for_date->get($package, 0)];
            });
            return $sales_with_zeros;
        });

        return $filled_sales;
    }

    private function getVerifiedCountsForEachInterval($eventId)
    {
        // Query to get the count of verified records in each 30-minute period for a particular event
        $results = DB::table('sub_tickets')
            ->join('ticket_sales', 'sub_tickets.sale_id', '=', 'ticket_sales.id')
            ->select(DB::raw("
            FROM_UNIXTIME(UNIX_TIMESTAMP(sub_tickets.verified_at) - (UNIX_TIMESTAMP(sub_tickets.verified_at) % 1800)) AS time_slot,
            COUNT(*) as verified_count
        "))
            ->where('ticket_sales.event_id', $eventId)
            ->whereNotNull('sub_tickets.verified_at')
            ->groupBy('time_slot')
            ->orderBy('time_slot')
            ->get();

        // Process the results without reformatting the time_slot since it's already in the correct format
        $formattedResults = $results->map(function ($item) {
            return [
                'time_slot' => $item->time_slot,  // time_slot is already in the correct format
                'verified_count' => $item->verified_count,
            ];
        });

        // Output the formatted results
        return $formattedResults;
    }

    public function removeEvent($id)
    {
        try {
            $event = Event::withCount('ticket_sales')->findOrFail($id);

            if ($event->ticket_sales_count > 0) {
                return (object) [
                    "message" => 'event cannot delete, related data exist',
                    "status" => false,
                    "code" => 400
                ];
            }

            $event->delete();

            return (object) [
                "message" => 'event deleted successfully',
                "status" => true,
                "data" => $event,
            ];
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
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

    public function updateEvent(Request $request, $id)
    {
        DB::beginTransaction();
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'venue' => 'required|integer|exists:venues,id',
                'organizer' => 'required|string',
                'manager' => 'required|string',
                'start_date' => 'required|string',
                'end_date' => 'required|string',
                'free_seating' => 'required',
                'currency' => 'required|string',
                "invoice.*.name"  => "required|string",
                "invoice.*.desc"  => "required|string",
                "invoice.*.price"  => "required|string",
                "invitation_feature" => 'required',
                "payment_gateways" => 'required'
            ]);

            if ($validator->fails()) {
                return (object) [
                    "message" => 'validation failed',
                    "status" => false,
                    "errors" => $validator->messages()->toArray(),
                    "code" => 422,
                    "data" => []
                ];
            }

            $banner_img_filename = null;
            $thumbnail_img_filename = null;
            $free_seating = $request->input('free_seating') === "true";

            /*if ($request->file('banner_img')) {
                $banner_img_file = $request->file('banner_img');
                $banner_img_filename = strtolower(str_replace(' ', '-', $request->input('name'))) . "-banner." . $request->file('banner_img')->extension();
                
                $path = public_path() . '/events/' . $banner_img_file->getClientOriginalName();
                if (file_exists($path)) {
                    unlink($path);
                }

               $banner_img_file->move(public_path('events'), $banner_img_filename);
            }

            if ($request->file('thumbnail_img')) {
                $thumbnail_img_file = $request->file('thumbnail_img');
                $thumbnail_img_filename = strtolower(str_replace(' ', '-', $request->input('name'))) . "-thumbnail." . $request->file('thumbnail_img')->extension();
                
                $path = public_path() . '/events/' . $thumbnail_img_file->getClientOriginalName();
                if (file_exists($path)) {
                    unlink($path);
                }

                $thumbnail_img_file->move(public_path('events'), $thumbnail_img_filename);
            }*/

            if ($request->file('banner_img')) {
                $banner_img_file = $request->file('banner_img');
                $banner_img_filename = null; //date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-banner." . $request->file('banner_img')->extension();
                //$banner_img_file->move(public_path('events'), $banner_img_filename);
                try {
                    $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events", $banner_img_file);
                    $banner_img_filename = Storage::disk('s3')->url($path);
                    //dd($path, $banner_img_filename);
                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $banner_img_filename);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            if ($request->file('thumbnail_img')) {
                $thumbnail_img_file = $request->file('thumbnail_img');
                $thumbnail_img_filename = null; //date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-thumbnail." . $request->file('thumbnail_img')->extension();
                //$thumbnail_img_file->move(public_path('events'), $thumbnail_img_filename);
                try {
                    $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events", $thumbnail_img_file);
                    $thumbnail_img_filename = Storage::disk('s3')->url($path);

                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $thumbnail_img_filename);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            $event = Event::with('venue', 'ticket_packages', 'managerr', 'payment_gateways')->findOrFail($id);
            $event->name = $request->input('name');
            $event->json_desc = $request->input('description');
            $event->venue_id = $request->input('venue');
            $event->organizer = $request->input('organizer');
            $event->free_seating = $free_seating;
            $event->manager = $request->input('manager');
            $event->start_date = $request->input('start_date');
            $event->end_date = $request->input('end_date');
            $event->type = $request->input('type');
            $event->sub_type = $request->input('sub_type');
            $event->status = $request->input('status');
            $event->message = $request->input('message');
            $event->handling_cost = $request->input('handling_cost');
            $event->handling_cost_perc = $request->input('handling_cost_perc') === "true" ? 1 : 0;
            $event->currency = $request->input('currency');
            $event->invitation_feature = $request->input('invitation_feature') === "true" ? 1 : 0;
            $event->invitation_count = $request->input('invitation_count');
            $event->trailer_url = $request->input('trailer_url');
            $event->addons_feature = $request->input('addons_feature') === "true" ? 1 : 0;

            if ($banner_img_filename) {
                $event->banner_img = $banner_img_filename;
            }
            if ($thumbnail_img_filename) {
                $event->thumbnail_img = $thumbnail_img_filename;
            }

            $event->featured = $request->input('featured') === "true" ? 1 : 0;
            $event->name = $request->input('name');

            $event->save();

            $packages = json_decode($request->input('invoice'), true);

            foreach ($packages as $package) {
                $validator = Validator::make($package, [
                    'packageName' => 'required',
                    'packagePrice' => 'required',
                    'packageQty' => 'required',
                    'packageAvailQty' => 'required',
                    'packageResQty' => 'required',
                    'maxBuyTickets' => 'required',
                ]);

                if ($validator->fails()) {
                    return (object) [
                        "message" => 'validation failed',
                        "status" => false,
                        "errors" => $validator->messages()->toArray(),
                        "code" => 422,
                        "data" => []
                    ];
                }
                Log::debug('Packages update started');
                if (isset($package['packageId']) && !$package['deleted']) {
                    Log::debug('Package update started: ' . $package['packageId']);
                    $tick_package = TicketPackage::with('promotions')->findOrFail($package['packageId']);
                    $tick_package->name = $package['packageName'];
                    $tick_package->desc = $package['packageDesc'];
                    $tick_package->price = $package['packagePrice'];
                    $tick_package->tot_tickets = $package['packageQty'];
                    $tick_package->aval_tickets = $package['packageAvailQty'];
                    $tick_package->res_tickets = $package['packageResQty'];
                    $tick_package->free_seating = $package['packageFreeSeating'] === true ? 1 : 0;
                    $tick_package->active = $package['active'] === true ? 1 : 0;
                    $tick_package->max_tickets_can_buy = $package['maxBuyTickets'];
                    $tick_package->sold_out =  $package['sold_out'] === true ? 1 : 0;

                    if (!$free_seating) {
                        if (!$package['packageFreeSeating']) {
                            $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                            $avail_seats_arr = explode(",", $package['packageAvailSeats']);
                            //$res_seats_arr = explode(",", $package['packageResSeats']);

                            $tick_package->seating_range = $alloc_seats_arr;
                            //$tick_package->reserved_seats = $res_seats_arr;
                            $tick_package->available_seats = $avail_seats_arr;
                        }
                    }

                    $tick_package->save();

                    $promotions = $tick_package->promotions;
                    Log::debug('Packages promotions update started');
                    if ($package['promotions'] === true) {

                        foreach ($package['promotion'] as $promo) {

                            if (isset($promo['promoId'])) {
                                Log::debug('Packages promotions update started' . $promo['promoId']);
                                $promotion = Promotion::findOrFail($promo['promoId']);

                                $promotion->coupon_code = $promo["promoCode"];
                                $promotion->discount_amount = $promo["discAmount"];
                                $promotion->percentage = $promo["discAmtIsPercentage"];
                                $promotion->min_tickets = $promo["minTickets"];
                                $promotion->min_amount = $promo["minAmount"];
                                $promotion->max_tickets = $promo["maxTickets"];
                                $promotion->max_amount = $promo["maxAmount"];
                                $promotion->start_date = $promo["startDateTime"];
                                $promotion->end_date = $promo["endDateTime"];
                                $promotion->per_ticket = $promo["isPerTicket"];
                                $promotion->auto_apply = $promo["isAutoApply"];
                                $promotion->redeems = $promo["redeems"];

                                $promotion->save();
                            } else {
                                Log::debug('New Packages promotions create started');
                                $newPackagePromotion = Promotion::create([
                                    'event_id' => $event->id,
                                    'package_id' => $tick_package->id,
                                    'coupon_code' => $promo["promoCode"],
                                    'discount_amount' => $promo["discAmount"],
                                    'percentage' => $promo["discAmtIsPercentage"],
                                    'min_tickets' => $promo["minTickets"],
                                    'min_amount' => $promo["minAmount"],
                                    'max_tickets' => $promo["maxTickets"],
                                    'max_amount' => $promo["maxAmount"],
                                    'start_date' => $promo["startDateTime"],
                                    'end_date' => $promo["endDateTime"],
                                    'per_ticket' => $promo["isPerTicket"]
                                ]);
                            }
                        }
                    } else {
                        foreach ($package['promotion'] as $promo) {
                            if (isset($promo['promoId'])) {
                                $promotion = Promotion::findOrFail($promo['promoId']);
                                $promotion->delete();
                                Log::debug('Packages promotion deleted: ' . $promo['promoId']);
                            }
                        }
                    }
                } else if ($package['deleted']) {
                    $tick_package = TicketPackage::withCount('ticket_sales')->findOrFail($package['packageId']);
                    if ($tick_package->ticket_sales_count > 0) {
                        throw new Exception('Ticket Package cannot delete, related sales exist', 400);
                    }
                    $tick_package->delete();
                } else {
                    $alloc_seats_arr = [];
                    $avail_seats_arr = [];
                    $res_seats_arr = [];

                    if (!$free_seating) {
                        $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                        $avail_seats_arr = explode(",", $package['packageAvailSeats']);
                        //$res_seats_arr = explode(",", $package['packageResSeats']);
                    }

                    $newTicketPackage = TicketPackage::create([
                        'name' => $package['packageName'],
                        'desc' => $package['packageDesc'],
                        'price' => $package['packagePrice'],
                        'seating_range' => $alloc_seats_arr,
                        'tot_tickets' => $package['packageQty'],
                        'aval_tickets' => $package['packageAvailQty'],
                        'event_id' => $event->id,
                        'reserved_seats' => $res_seats_arr,
                        'available_seats' => $avail_seats_arr,
                        'free_seating' => $package['packageFreeSeating'] === true ? 1 : 0,
                        'active' => $package['active'] === true ? 1 : 0,
                        'max_tickets_can_buy' => $package['maxBuyTickets']
                    ]);
                }
            }

            if ($request->input('invitation_feature') === "true") {
                $invitation_packages = json_decode($request->input('invitation_packages'), true);

                $allDeleted = count($invitation_packages) === count(array_filter($invitation_packages, function ($package) {
                    return isset($package['deleted']) && $package['deleted'] === true;
                }));

                if ($allDeleted) {
                    $event->invitation_feature = false;
                    $event->save();
                }

                foreach ($invitation_packages as $package) {
                    $validator = Validator::make($package, [
                        'packageName' => 'required',
                        'packageDesc' => 'required',
                        'packageAvailQty' => 'required'
                    ]);

                    if ($validator->fails()) {
                        return (object) [
                            "message" => 'validation failed',
                            "status" => false,
                            "errors" => $validator->messages()->toArray(),
                            "code" => 422,
                            "data" => []
                        ];
                    }
                    Log::debug('Invitation packages update started');
                    if (isset($package['packageId']) && !$package['deleted']) {
                        Log::debug('Package update started: ' . $package['packageId']);
                        $tick_package = TicketPackage::findOrFail($package['packageId']);
                        $tick_package->name = $package['packageName'];
                        $tick_package->desc = $package['packageDesc'];
                        $tick_package->price = $package['packagePrice'];
                        $tick_package->tot_tickets = $package['packageAvailQty'];
                        $tick_package->aval_tickets = $package['packageAvailQty'];
                        $tick_package->free_seating = $package['packageFreeSeating'] === true ? 1 : 0;
                        $tick_package->active = $package['active'] === true ? 1 : 0;

                        if (!$free_seating) {
                            if (!$package['packageFreeSeating']) {
                                $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                                //$res_seats_arr = explode(",", $package['packageResSeats']);

                                $tick_package->seating_range = $alloc_seats_arr;
                            }
                        }

                        $tick_package->save();
                    } else if ($package['deleted']) {
                        $tick_package = TicketPackage::withCount('ticket_sales')->findOrFail($package['packageId']);
                        if ($tick_package->ticket_sales_count > 0) {
                            throw new Exception('Ticket Package cannot delete, related sales exist', 400);
                        }
                        $tick_package->delete();
                    } else {
                        $alloc_seats_arr = [];

                        if (!$free_seating) {
                            $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                        }

                        $newTicketPackage = TicketPackage::create([
                            'event_id' => $event->id,
                            'name' => $package['packageName'],
                            'desc' => $package['packageDesc'],
                            'price' => 0,
                            'seating_range' => $alloc_seats_arr,
                            'tot_tickets' => $package['packageAvailQty'],
                            'aval_tickets' => $package['packageAvailQty'],
                            'free_seating' => $package['packageFreeSeating'] === true ? 1 : 0,
                            'active' => $package['active'] === true ? 1 : 0,
                            'private' => 1
                        ]);
                    }
                }
            }

            if ($request->input('addons_feature') === "true") {
                $addons = json_decode($request->input('addons'), true);

                $allDeleted = count($addons) === count(array_filter($addons, function ($addon) {
                    return isset($addon['deleted']) && $addon['deleted'] === true;
                }));

                if ($allDeleted) {
                    $event->addons_feature = false;
                    $event->save();
                }

                $addonImages = $request->file('addonImage');

                foreach ($addons as $index => $addon) {
                    $validator = Validator::make($addon, [
                        'addonName' => 'required',
                        'addonPrice' => 'required',
                        'addonCategory' => 'required'
                    ]);

                    if ($validator->fails()) {
                        return (object) [
                            "message" => 'validation failed',
                            "status" => false,
                            "errors" => $validator->messages()->toArray(),
                            "code" => 422,
                            "data" => []
                        ];
                    }

                    Log::debug('Addons update started');

                    if (isset($addon['addonId']) && !$addon['deleted']) {
                        Log::debug('Addon update started: ' . $addon['addonId']);
                        $event_addon = EventAddon::findOrFail($addon['addonId']);
                        $event_addon->name = $addon['addonName'];
                        $event_addon->price = $addon['addonPrice'];
                        $event_addon->category = $addon['addonCategory'];

                        if (isset($addonImages[$index]) && $addonImages[$index]) {
                            // Upload the image to S3
                            $addon_img_file = $addonImages[$index]; // Assuming you already have this file in the $addonImage array
                            try {
                                $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events/addons", $addon_img_file);
                                $addon_img_filename = Storage::disk('s3')->url($path);

                                Log::debug('S3 file upload successful: ' . $addon_img_filename);

                                // Store the image URL in the addon model
                                $event_addon->image_url = $addon_img_filename;
                            } catch (Exception $err) {
                                Log::debug('S3 file upload error: ' . $err->getMessage());
                            }
                        }

                        $event_addon->save();
                    } else if ($addon['deleted']) {
                        $event_addon = EventAddon::findOrFail($addon['addonId']);
                        $event_addon->delete();
                    } else {

                        $newTicketPackage = EventAddon::create([
                            'event_id' => $event->id,
                            'name' => $addon['addonName'],
                            'price' => $addon['addonPrice'],
                            'category' => $addon['addonCategory'],
                        ]);

                        if (isset($addonImages[$index]) && $addonImages[$index]) {
                            // Upload the image to S3
                            $addon_img_file = $addonImages[$index]; // Assuming you already have this file in the $addonImage array
                            try {
                                $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events/addons", $addon_img_file);
                                $addon_img_filename = Storage::disk('s3')->url($path);

                                Log::debug('S3 file upload successful: ' . $addon_img_filename);

                                // Store the image URL in the addon model
                                $newTicketPackage->image_url = $addon_img_filename;
                            } catch (Exception $err) {
                                Log::debug('S3 file upload error: ' . $err->getMessage());
                            }
                        }

                        $newTicketPackage->save();
                    }
                }
            }

            $payment_gateways = json_decode($request->input('payment_gateways'), true);

            // Get the current count of payment gateways before making any updates
            $existingGatewaysCount = $event->payment_gateways()->count();

            $incomingGatewaysCount = count($payment_gateways);

            // Get the maximum count between the existing and incoming payment gateways
            $maxGatewaysCount = max($existingGatewaysCount, $incomingGatewaysCount);

            foreach ($payment_gateways as $payment_gateway) {
                // Validate the payment gateway input
                $validator = Validator::make($payment_gateway, [
                    'id' => 'required|string',
                ]);

                if ($validator->fails()) {
                    return (object) [
                        "message" => 'validation failed',
                        "status" => false,
                        "errors" => $validator->messages()->toArray(),
                        "code" => 422,
                        "data" => []
                    ];
                }

                Log::debug('Payment gateways update started');

                // Check if this payment gateway is being deleted
                if (isset($payment_gateway['deleted']) && $payment_gateway['deleted'] === true) {
                    // Ensure the event will still have at least one payment gateway after this deletion
                    if ($maxGatewaysCount === 1) {
                        throw new Exception('The event must have at least one payment gateway.', 422);
                    }

                    // Proceed with deletion
                    $event->payment_gateways()->detach($payment_gateway['id']);
                    Log::info("Payment gateway {$payment_gateway['id']} removed from event {$event->id}");

                    // Reduce count since we successfully removed one
                    $maxGatewaysCount--;
                } else {
                    // Check if the event already has this payment gateway
                    $existingGateway = $event->payment_gateways()->where('payment_gateway_id', $payment_gateway['id'])->first();

                    if (!$existingGateway) {
                        $event->payment_gateways()->attach($payment_gateway['id']);
                        Log::info("Payment gateway {$payment_gateway['id']} added to event {$event->id}");
                        $maxGatewaysCount++; // Increase count since we added one
                    }
                }
            }
          
            if ($request->filled('analytics_ids')) {
                // Decode the input JSON string into an array
                $inputAnalytics = json_decode($request->input('analytics_ids'), true) ?? [];

                // Initialize a new array for analytics IDs
                $analyticsIds = [];

                // Only include the platforms sent in the request
                foreach ($inputAnalytics as $item) {
                    if (isset($item['platform'], $item['pixel_code'])) {
                        $analyticsIds[$item['platform']] = $item['pixel_code'];
                    }
                }

                // Save back to the event (Laravel will handle JSON storage automatically)
                $event->analytics_ids = $analyticsIds;
                $event->save();

            }

            DB::commit();

            return (object) [
                "message" => 'event updated successfully',
                "status" => true,
                "data" => $event->serialize()
            ];
        } catch (ResourceNotFoundException $e) {
            DB::rollBack();
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getManagerEvents(Request $request)
    {

        try {
            $manager_id = Auth::user()->id;

            $query = Event::query();

            if (Auth::user()->hasRole(Roles::ADMIN->value)) {
                $query->with('ticket_packages');
            } else if (Auth::user()->hasRole(Roles::MANAGER->value)) {
                $query->with('ticket_packages')->where([
                    ['manager', '=', $manager_id],
                ]);
            }

            $query->when($request->input('status'), function ($q, $statuses) {
                $q->whereIn('status', $statuses);
            });

            $query->when($request->input('limit'), function ($q, $limit) {
                $q->limit($limit);
            });

            $query->when($request->input('type'), function ($q, $type) {
                $q->where('type', $type);
            });

            $events = $query->get()->map->serialize();

            return (object) [
                "message" => 'events retreived successfully',
                "status" => true,
                "data" => $events,
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

    public function sendInvitations(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'event_id' => 'required|integer|exists:events,id',
            'invitees' => 'required|array'
        ]);

        if ($validator->fails()) {
            return (object) [
                "message" => 'validation failed',
                "status" => false,
                "errors" => $validator->messages()->toArray(),
                "code" => 422,
                "data" => []
            ];
        }

        try {

            $invitees = $request->input('invitees');

            if (!$this->checkManager($request->input('event_id'))) {
                return (object) [
                    "message" => 'Requested event not found.',
                    "status" => false,
                    "code" => 401
                ];
            }

            $event = Event::with('managerr', 'venue')->findOrFail($request->input('event_id'));

            $tot_tickets_count = 0;

            foreach ($invitees as $invitee) {
                $tot_tickets_count += $invitee['Tickets'];
            }

            if (!$event->invitation_feature) {
                return (object) [
                    "message" => 'Event does not have access to this feature. Please contact SpotSeeker for assistance',
                    "status" => false,
                    "code" => 400
                ];
            } else if ($event->status != EventStatus::ONGOING->value) {
                return (object) [
                    "message" => 'Event should be ongoing to use this feature. Please contact SpotSeeker for assistance',
                    "status" => false,
                    "code" => 400
                ];
            } else if ($event->invitation_count == 0) {
                return (object) [
                    "message" => 'Free invitation count allocated for this event is over. Please contact SpotSeeker for assistance',
                    "status" => false,
                    "code" => 400
                ];
            } else if ($event->invitation_count < $tot_tickets_count) {

                return (object) [
                    "message" => 'Only' . $event->invitation_count . ' free invitations remaining. Please reduce the number of invitees or contact SpotSeeker for assistance',
                    "status" => false,
                    "code" => 400
                ];
            }

            $details['event_id'] = $event->id;
            $details['event_name'] = $event->name;
            $details['event_venue'] = $event->venue->name;
            $details['event_date'] = $event->start_date;
            $details['event_manager'] = $event->organizer;
            $details['event_manager_logo_url'] = $event->managerr->profile_photo_path;

            $alreadySentInvites = [];
            $invitesSend = [];
            $invitationSent = 0;
            $batchInvitationDetails = []; // Array to collect invitation details for batch sending

            foreach ($invitees as $invitee) {

                $validator = Validator::make($invitee, [
                    'Email' => 'required|string',
                    'Name' => 'required|string',
                    'Package' => 'required|string',
                    'Tickets' => 'required|integer',
                    "SeatNos" => 'string'
                ]);

                if ($validator->fails()) {
                    return (object) [
                        "message" => 'validation failed',
                        "status" => false,
                        "errors" => $validator->messages()->toArray(),
                        "code" => 422,
                        "data" => []
                    ];
                }

                $ticket_package = TicketPackage::where([
                    ['event_id', '=', $request->input('event_id')],
                    ['name', '=', $invitee['Package']],
                    ['private', '=', true]
                ])->first();

                if ($ticket_package == null) {
                    return (object) [
                        "message" => $invitee['Package'] . " package not found. Please check the invitation package name is correct",
                        "status" => false,
                        "code" => 400
                    ];
                }

                if ($ticket_package->aval_tickets < $invitee['Tickets']) {
                    return (object) [
                        "message" => 'Free invitation count allocated for this package is over. Please contact SpotSeeker for assistance',
                        "status" => false,
                        "code" => 400
                    ];
                } else if ($event->invitation_count < $invitee['Tickets']) {
                    return (object) [
                        "message" => 'Free invitation count allocated for this event is over. Please contact SpotSeeker for assistance',
                        "status" => false,
                        "code" => 400
                    ];
                }

                $user = User::where('email', $invitee['Email'])->first();

                $name_arr = explode(" ", $invitee['Name']);
                $first_name = $name_arr[0];
                $last_name = count($name_arr) > 1 ? implode(" ", array_slice($name_arr, 1)) : '';

                if ($user == null) {

                    $user = User::create([
                        'email' => $invitee['Email'],
                        'name' => $invitee['Name'],
                        'first_name' => $first_name,
                        'last_name' => $last_name
                    ]);

                    $role = Role::firstOrCreate(['name' => 'User']);
                    $user->assignRole([$role->id]);
                } else {
                    $user->update([
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'name' => $invitee['Name']
                    ]);
                }


                $invitationExists = EventInvitation::with('event', 'user')->where('user_id', $user->id)
                    ->where('event_id', $request->input('event_id'))
                    ->exists();

                if ($invitationExists) {
                    array_push($alreadySentInvites, $invitee['Email']);
                } else {

                    $details['user_id'] = $user->id;
                    $details['email'] = $invitee['Email'];
                    $details['username'] = explode(" ", $invitee['Name'])[0];

                    $invitation = EventInvitation::create([
                        'user_id' => $user->id,
                        'event_id' => $event->id,
                        'invitation_id' => $this->generateOrderId($event->name, false, null, true),
                        'tickets_count' => $invitee['Tickets'],
                        'package_id' => $ticket_package->id,
                        'status' => 'delivered'
                    ]);

                    $ticket_sale = TicketSale::create([
                        'user_id' => $invitation->user_id,
                        'event_id' => $invitation->event_id,
                        'payment_status' => 'complete',
                        'booking_status' => 'complete',
                        'order_id' => $invitation->invitation_id,
                        'transaction_date_time' => \Carbon\Carbon::now(),
                        'tot_ticket_count' => $invitation->tickets_count,
                        'comment' => 'Invitation ticket'
                    ]);
    
                    $newTicketSalePackage = TicketSalePackage::create([
                        'sale_id' => $ticket_sale->id,
                        'package_id' => $invitation->package_id,
                        'ticket_count' => $invitation->tickets_count,
                        'seat_nos' => $invitation->seat_nos
                    ]);
    
                    for ($i = 0; $i < $invitation->tickets_count; $i++) {
                        $newSubTicket = SubTicket::create([
                            'sale_id' => $ticket_sale->id,
                            'package_id' => $newTicketSalePackage->id,
                            'sub_order_id' => $this->generateOrderId($invitation->event->name, true, null, true),
                            'booking_status' => 'complete'
                        ]);
                    }
    
                    $details['email'] = $invitee['Email'];
                    $details['order_id'] = $ticket_sale->order_id;
                    $details['tot_amount'] =  0;
                    $details['payment_ref_no'] = $ticket_sale->order_id;
                    $details['packages']  = [$newTicketSalePackage];
                    $details['tot_ticket_count']  = $ticket_sale->tot_ticket_count;
                    $details['event_uid']  = $event->uid;
                    $details['event_name']  = $event->name;
                    $details['event_venue']  = $event->venue->name;
                    $details['event_date']  = $event->start_date;
                    $details['event_end_date']  = $event->end_date;
                    $details['event_banner']  = $event->thumbnail_img;
                    $details['currency']  = $event->currency;
                    $details['free_seating']  = $event->free_seating;
                    $details['cust_name'] = $user->name;
                    $details['cust_email'] = $invitee['Email'];
                    $details['cust_mobile'] = $user->phone_no;
                    $details['cust_id'] = $user->nic;
                    $details['message'] = $this->generateOrderConfirmationSMS($details);
                    $details['S3Path'] = env('AWS_BUCKET_PATH');
                    $details['invitation'] = true;
                    $details['qrCode'] = $this->qrCodeGenerator($ticket_sale->order_id)->getDataUri();
                    $details['addons'] = $ticket_sale->addons;

                    // Upload ticket to S3 immediately
                    Bus::dispatch(new UploadTicketJob($details));
                    
                    // Collect invitation details for batch sending
                    $batchInvitationDetails[] = $details;

                    $ticket_package->aval_tickets -= $invitee['Tickets'];
                    $ticket_package->save();

                    array_push($invitesSend, $invitation);

                    $invitationSent++;
                }
            }

            // Send all invitations in batch after processing all invitees
            if (!empty($batchInvitationDetails)) {
                // Split into batches of 100 (Resend's recommended batch size)
                $batchSize = 100;
                $batches = array_chunk($batchInvitationDetails, $batchSize);
                
                foreach ($batches as $batch) {
                    SendBatchEventInvitationJob::dispatch($batch);
                }
            }

            if ($invitationSent != $tot_tickets_count) {
                $event->invitation_count = $event->invitation_count - ($tot_tickets_count - $invitationSent);
            } else {
                $event->invitation_count = $event->invitation_count - $tot_tickets_count;
            }

            $event->save();

            $dataObj = (object) [
                "invites_already_sent" => $alreadySentInvites,
                "invites_send" => sizeof($invitesSend)
            ];

            if (sizeof($alreadySentInvites) > 0) {
                return (object) [
                    "message" => 'Some invitations already sent',
                    "status" => false,
                    "errors" => $dataObj,
                    "code" => 400
                ];
            } else {
                return (object) [
                    "message" => 'event invitations sent successfully',
                    "status" => true,
                    "data" => []
                ];
            }
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getInvitation($id)
    {

        try {
            $invitation = EventInvitation::with('user', 'event', 'package')->where('invitation_id', $id)->firstOrFail();

            [$startDate, $startTime] = explode(" ", $invitation->event->start_date);

            $invitationObj['event_name'] = $invitation->event->name ?? null;
            $invitationObj['event_id'] = $invitation->event->id ?? null;
            $invitationObj['event_venue'] = $invitation->event->venue->name ?? null;
            $invitationObj['event_thumb_img'] = $invitation->event->thumbnail_img ?? null;
            $invitationObj['event_start_date'] = $startDate;
            $invitationObj['event_start_time'] = $startTime;
            $invitationObj['event_organizer'] = $invitation->event->organizer;
            $invitationObj['package_name'] = $invitation->package->name;
            $invitationObj['user_name'] = $invitation->user->name;
            $invitationObj['user_email'] = $invitation->user->email;
            $invitationObj['tickets_count'] = $invitation->tickets_count;
            $invitationObj['status'] = $invitation->status;

            return (object) [
                "message" => 'event invitation retrieved successfully',
                "status" => true,
                "data" => $invitationObj
            ];
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => 'invitation retrieval failed',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function invitationRSVP(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invitation_id' => 'required|string|exists:event_invitations,invitation_id',
            'status' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return (object) [
                "message" => 'validation failed',
                "status" => false,
                "errors" => $validator->messages()->toArray(),
                "code" => 422,
                "data" => []
            ];
        }

        try {
            $invitation = EventInvitation::with('user', 'event', 'package')->where('invitation_id', $request->input('invitation_id'))->first();

            if ($request->input('status')) {
                $invitation->status = 'accepted';

                $ticket_sale = TicketSale::create([
                    'user_id' => $invitation->user_id,
                    'event_id' => $invitation->event_id,
                    'payment_status' => 'complete',
                    'booking_status' => 'complete',
                    'order_id' => $request->input('invitation_id'),
                    'transaction_date_time' => \Carbon\Carbon::now(),
                    'tot_ticket_count' => $invitation->tickets_count,
                    'comment' => 'Invitation ticket'
                ]);

                $newTicketSalePackage = TicketSalePackage::create([
                    'sale_id' => $ticket_sale->id,
                    'package_id' => $invitation->package_id,
                    'ticket_count' => $invitation->tickets_count,
                    'seat_nos' => $invitation->seat_nos
                ]);

                for ($i = 0; $i < $invitation->tickets_count; $i++) {
                    $newSubTicket = SubTicket::create([
                        'sale_id' => $ticket_sale->id,
                        'package_id' => $newTicketSalePackage->id,
                        'sub_order_id' => $this->generateOrderId($invitation->event->name, true, null, true),
                        'booking_status' => 'complete'
                    ]);
                }

                $details['email'] = $invitation->user->email;
                $details['tot_amount'] =  "INVITATION";
                $details['order_id'] = $ticket_sale->order_id;
                $details['transaction_date_time'] = $ticket_sale->transaction_date_time;
                $details['payment_ref_no'] = "INVITATION";
                $details['packages']  = [$newTicketSalePackage];
                $details['tot_ticket_count']  = $invitation->tickets_count;
                $details['event_name']  = $invitation->event->name;
                $details['event_venue']  = $invitation->event->venue->name;
                $details['event_date']  = $invitation->event->start_date;
                $details['event_end_date']  = $invitation->event->end_date;
                $details['event_banner']  = $invitation->event->banner_img;
                $details['event_manager'] = $invitation->event->organizer;
                $details['currency']  = $invitation->event->currency;
                $details['free_seating']  = $invitation->event->free_seating;
                $details['cust_name'] = $invitation->user->name;
                $details['invitation'] = true;
                $details['S3Path'] = env('AWS_BUCKET_PATH');
                $details['qrCode'] = $this->qrCodeGenerator($ticket_sale->order_id)->getDataUri();

                Bus::chain([
                    new UploadTicketJob($details),
                    new SendEmailJob($details)
                ])->dispatch();
            } else {
                $invitation->status = 'rejected';
            }

            $invitation->save();

            return (object) [
                "message" => 'event invitation updated successfully',
                "status" => true,
                "data" => []
            ];
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getInvitations($request, $id)
    {

        try {
            if (!$this->checkManager($id)) {
                return (object) [
                    "message" => 'Requested event not found.',
                    "status" => false,
                    "code" => 401
                ];
            }

            $invitations = EventInvitation::with('user', 'event', 'package')->where('event_id', $id)->get();
            $event = Event::findOrFail($id);

            $obj = (object) [
                "invitations" => $invitations,
                "event" => $event
            ];

            return (object) [
                "message" => 'invitations retrieved successfully',
                "status" => true,
                "data" => $obj
            ];
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getManagerEventById($id)
    {
        try {

            if (!$this->checkManager($id)) {
                return (object) [
                    "message" => 'Cannot find the requested event',
                    "status" => false,
                    "code" => 400
                ];
            }

            $event = Event::with('venue', 'ticket_packages', 'managerr', 'ticket_packages.promotions', 'coordinators')->findOrFail($id);

            foreach ($event->ticket_packages as $package) {
                $pending_ticket_count = DB::table('ticket_sales')
                    ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                    ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                    ->where([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'pending'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->pluck('ticket_count')[0];
                $package['aval_tickets'] = $package->aval_tickets + (int)$pending_ticket_count;
                $package['sold_tickets'] = DB::table('ticket_sales')
                    ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                    ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                    ->where([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'complete'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->orWhere([
                        ['ticket_sales.event_id', $event->id],
                        ['ticket_sales.payment_status', 'verified'],
                        ['ticket_sale_packages.package_id', $package->id],
                        ['ticket_sales.deleted_at', null]
                    ])
                    ->pluck('ticket_count')[0];
            }

            if (Auth::check() && Auth::user()->hasRole('Manager')) {

                foreach ($event->ticket_packages as $pack) {
                    $pack['sold_out'] = $pack->aval_tickets == 0 || !$pack->active;
                    $pack['promo'] = sizeof($pack->promotions) > 0 ? true : false;
                }

                return (object) [
                    "message" => 'event retreived successfully',
                    "status" => true,
                    "data" => $event,
                ];
            } else {
                if (!($event->status == 'pending' || $event->status == 'ongoing')) {
                    throw new ResourceNotFoundException("event not found");
                }
                return (object) [
                    "message" => 'event retreived successfully',
                    "status" => true,
                    "data" => $event->serialize(),
                ];
            }
        } catch (ResourceNotFoundException $e) {
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
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

    public function updateManagerEvent(Request $request, $id)
    {

        if (!$this->checkManager($id)) {
            return (object) [
                "message" => 'Cannot find the requested event',
                "status" => false,
                "code" => 400
            ];
        }

        DB::beginTransaction();
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'venue' => 'required|integer|exists:venues,id',
                'organizer' => 'required|string',
                'manager' => 'required|string|exists:users,id',
                'coordinators.*' => 'exists:event_coordinators,user_id',
                'start_date' => 'required|string',
                'end_date' => 'required|string',
                'free_seating' => 'required',
                'banner_img' => ['required', 'max:2048'],
                'thumbnail_img' => ['required', 'max:2048'],
                "invoice.*.name"  => "required|string",
                "invoice.*.desc"  => "required|string",
                "invoice.*.price"  => "required|string"
            ]);

            if ($validator->fails()) {
                return (object) [
                    "message" => 'validation failed',
                    "status" => false,
                    "errors" => $validator->messages()->toArray(),
                    "code" => 422,
                    "data" => []
                ];
            }

            $banner_img_filename = null;
            $thumbnail_img_filename = null;
            $free_seating = $request->input('free_seating') === "true";

            /*if ($request->file('banner_img')) {
                $banner_img_file = $request->file('banner_img');
                $banner_img_filename = strtolower(str_replace(' ', '-', $request->input('name'))) . "-banner." . $request->file('banner_img')->extension();
                
                $path = public_path() . '/events/' . $banner_img_file->getClientOriginalName();
                if (file_exists($path)) {
                    unlink($path);
                }

               $banner_img_file->move(public_path('events'), $banner_img_filename);
            }

            if ($request->file('thumbnail_img')) {
                $thumbnail_img_file = $request->file('thumbnail_img');
                $thumbnail_img_filename = strtolower(str_replace(' ', '-', $request->input('name'))) . "-thumbnail." . $request->file('thumbnail_img')->extension();
                
                $path = public_path() . '/events/' . $thumbnail_img_file->getClientOriginalName();
                if (file_exists($path)) {
                    unlink($path);
                }

                $thumbnail_img_file->move(public_path('events'), $thumbnail_img_filename);
            }*/

            if ($request->file('banner_img')) {
                $banner_img_file = $request->file('banner_img');
                $banner_img_filename = null; //date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-banner." . $request->file('banner_img')->extension();
                //$banner_img_file->move(public_path('events'), $banner_img_filename);
                try {
                    $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events", $banner_img_file);
                    $banner_img_filename = Storage::disk('s3')->url($path);
                    //dd($path, $banner_img_filename);
                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $banner_img_filename);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            if ($request->file('thumbnail_img')) {
                $thumbnail_img_file = $request->file('thumbnail_img');
                $thumbnail_img_filename = null; //date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-thumbnail." . $request->file('thumbnail_img')->extension();
                //$thumbnail_img_file->move(public_path('events'), $thumbnail_img_filename);
                try {
                    $path = Storage::disk('s3')->put(env('AWS_BUCKET_PATH') . "/events", $thumbnail_img_file);
                    $thumbnail_img_filename = Storage::disk('s3')->url($path);

                    Log::debug('put return: ' . $path);
                    Log::debug('banner img return: ' . $thumbnail_img_filename);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            $event = Event::with('venue', 'ticket_packages', 'managerr', 'coordinators')->findOrFail($id);
            $event->name = $request->input('name');
            $event->json_desc = $request->input('description');
            $event->venue_id = $request->input('venue');
            $event->organizer = $request->input('organizer');
            $event->free_seating = $free_seating;
            $event->manager = $request->input('manager');
            $event->start_date = $request->input('start_date');
            $event->end_date = $request->input('end_date');
            $event->type = $request->input('type');
            $event->sub_type = $request->input('sub_type');
            $event->message = $request->input('message');

            // Check if the coordinators input is different from the current coordinators
            if ($request->has('coordinators') && $event->coordinators()->pluck('users.id')->toArray() !== json_decode($request->input('coordinators'), true)) {
                // Sync coordinators with the event
                $event->coordinators()->sync(json_decode($request->input('coordinators'), true));
            }

            if ($banner_img_filename) {
                $event->banner_img = $banner_img_filename;
            }
            if ($thumbnail_img_filename) {
                $event->thumbnail_img = $thumbnail_img_filename;
            }
            $event->featured = $request->input('featured') === "true" ? 1 : 0;
            $event->name = $request->input('name');

            $event->save();

            $packages = json_decode($request->input('invoice'), true);

            foreach ($packages as $package) {
                $validator = Validator::make($package, [
                    'packageName' => 'required',
                    'packageDesc' => 'required',
                    'packagePrice' => 'required',
                    'packageQty' => 'required',
                    'packageAvailQty' => 'required',
                    'packageResQty' => 'required'
                ]);

                if ($validator->fails()) {
                    return (object) [
                        "message" => 'validation failed',
                        "status" => false,
                        "errors" => $validator->messages()->toArray(),
                        "code" => 422,
                        "data" => []
                    ];
                }
                Log::debug('Packages update started');
                $tick_package = null;
                if (isset($package['packageId']) && !$package['deleted']) {
                    Log::debug('Package update started: ' . $package['packageId']);
                    $tick_package = TicketPackage::with('promotions')->findOrFail($package['packageId']);
                    $tick_package->name = $package['packageName'];
                    $tick_package->desc = $package['packageDesc'];
                    $tick_package->price = $package['packagePrice'];
                    $tick_package->tot_tickets = $package['packageQty'];
                    $tick_package->aval_tickets = $package['packageAvailQty'];
                    $tick_package->res_tickets = $package['packageResQty'];
                    $tick_package->free_seating = $package['packageFreeSeating'] === true ? 1 : 0;
                    $tick_package->active = $package['active'] === true ? 1 : 0;

                    if (!$free_seating) {
                        if (!$package['packageFreeSeating']) {
                            $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                            $avail_seats_arr = explode(",", $package['packageAvailSeats']);
                            //$res_seats_arr = explode(",", $package['packageResSeats']);

                            $tick_package->seating_range = $alloc_seats_arr;
                            //$tick_package->reserved_seats = $res_seats_arr;
                            $tick_package->available_seats = $avail_seats_arr;
                        }
                    }

                    $tick_package->save();
                } else if ($package['deleted']) {
                    $tick_package = TicketPackage::withCount('ticket_sales')->findOrFail($package['packageId']);
                    if ($tick_package->ticket_sales_count > 0) {
                        throw new Exception('Ticket Package cannot delete, related sales exist', 400);
                    }
                    $tick_package->delete();
                } else {
                    $alloc_seats_arr = [];
                    $avail_seats_arr = [];
                    $res_seats_arr = [];

                    if (!$free_seating) {
                        $alloc_seats_arr = explode(",", $package['packageAllocSeats']);
                        $avail_seats_arr = explode(",", $package['packageAvailSeats']);
                        //$res_seats_arr = explode(",", $package['packageResSeats']);
                    }

                    $tick_package = TicketPackage::create([
                        'name' => $package['packageName'],
                        'desc' => $package['packageDesc'],
                        'price' => $package['packagePrice'],
                        'seating_range' => $alloc_seats_arr,
                        'tot_tickets' => $package['packageQty'],
                        'aval_tickets' => $package['packageAvailQty'],
                        'reserved_seats' => $res_seats_arr,
                        'available_seats' => $avail_seats_arr,
                        'free_seating' => $package['packageFreeSeating'] === "true" ? 1 : 0,
                        'active' => $package['active'] === true ? 1 : 0
                    ]);

                    $event->ticket_packages()->attach($tick_package);
                }

                $promotions = $tick_package->promotions;
                Log::debug('Packages promotions update started');
                if ($package['promotions'] === true) {

                    foreach ($package['promotion'] as $promo) {
                        $validator = Validator::make($promo, [
                            'promoCode' => 'required',
                            'discAmount' => 'required',
                            'discAmtIsPercentage' => 'required',
                            'isPerTicket' => 'required',
                            'isAutoApply' => 'required',
                            'redeems' => 'required',
                            'minTickets' => 'required',
                            'maxTickets' => 'required'
                        ]);

                        if ($validator->fails()) {
                            return (object) [
                                "message" => 'validation failed',
                                "status" => false,
                                "errors" => $validator->messages()->toArray(),
                                "code" => 422,
                                "data" => []
                            ];
                        }
                        if (isset($promo['promoId'])) {
                            Log::debug('Packages promotions update started' . $promo['promoId']);
                            $promotion = Promotion::findOrFail($promo['promoId']);

                            $promotion->coupon_code = $promo["promoCode"];
                            $promotion->discount_amount = $promo["discAmount"];
                            $promotion->percentage = $promo["discAmtIsPercentage"];
                            $promotion->min_tickets = $promo["minTickets"];
                            $promotion->min_amount = $promo["minAmount"];
                            $promotion->max_tickets = $promo["maxTickets"];
                            $promotion->max_amount = $promo["maxAmount"];
                            $promotion->start_date = $promo["startDateTime"];
                            $promotion->end_date = $promo["endDateTime"];
                            $promotion->per_ticket = $promo["isPerTicket"];
                            $promotion->auto_apply = $promo["isAutoApply"];
                            $promotion->redeems = $promo["redeems"];

                            $promotion->save();
                        } else {
                            Log::debug('New Packages promotions create started');
                            $newPackagePromotion = Promotion::create([
                                'event_id' => $event->id,
                                'package_id' => $tick_package->id,
                                'coupon_code' => $promo["promoCode"],
                                'discount_amount' => $promo["discAmount"],
                                'percentage' => $promo["discAmtIsPercentage"],
                                'min_tickets' => $promo["minTickets"],
                                'min_amount' => $promo["minAmount"],
                                'max_tickets' => $promo["maxTickets"],
                                'max_amount' => $promo["maxAmount"],
                                'start_date' => $promo["startDateTime"],
                                'end_date' => $promo["endDateTime"],
                                'per_ticket' => $promo["isPerTicket"],
                                'redeems' => $promo["redeems"]
                            ]);
                        }
                    }
                } else {
                    foreach ($package['promotion'] as $promo) {
                        if (isset($promo['promoId'])) {
                            $promotion = Promotion::findOrFail($promo['promoId']);
                            $promotion->delete();
                            Log::debug('Packages promotion deleted: ' . $promo['promoId']);
                        }
                    }
                }
            }

            DB::commit();

            return (object) [
                "message" => 'event updated successfully',
                "status" => true,
                "data" => $event->serialize()
            ];
        } catch (ResourceNotFoundException $e) {
            DB::rollBack();
            return (object) [
                "message" => 'event cannot find in our system',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404,
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function addPixelCodes(Request $request)
    {
        try {
            if (!$this->checkManager($request->event_id) || !Auth::user()->hasRole('Admin')) {
                return (object) [
                    "message" => 'Requested event not found.',
                    "status" => false,
                    "code" => 401
                ];
            }

            // Find the event by ID
            $event = Event::findOrFail($request->event_id);

            // Get current analytics_ids or initialize as empty array if null
            $analyticsIds = $event->analytics_ids ?? [];

            // If analytics_ids is a JSON string, decode it
            if (is_string($analyticsIds)) {
                $analyticsIds = json_decode($analyticsIds, true) ?? [];
            }

            // Add or update the platform and pixelCode
            $analyticsIds[$request->platform] = $request->pixel_code;

            // Update the event with the new analytics_ids
            $event->analytics_ids = $analyticsIds;
            $event->save();

            return (object) [
                "message" => 'Pixel code added successfully',
                "status" => true,
                "code" => 200,
                "data" => $event
            ];
        } catch (\Exception $e) {
            return (object) [
                "message" => 'Failed to add pixel code',
                "status" => false,
                "errors" => [$e->getMessage()],
                "code" => 500,
                "data" => []
            ];
        }
    }

    public function getPixelsByEvent($id)
    {
        try {
            $event = Event::findOrFail($id);

            $analyticsIds = $event->analytics_ids ?? [];

            $res = (object) [
                'analytics_ids' => $analyticsIds
            ];

            return (object) [
                "message" => 'Event analytics codes retrieved successfully',
                "status" => true,
                "code" => 200,
                "data" => $res
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'Failed to retrieve event analytics codes',
                "status" => false,
                "errors" => [$e->getMessage()],
                "code" => 500,
                "data" => []
            ];
        }
    }

    private function generateEventUID()
    {
        return uniqid();
    }
}
