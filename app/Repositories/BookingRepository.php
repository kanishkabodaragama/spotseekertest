<?php

namespace App\Repositories;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendEmailJob;
use App\Jobs\SendSMSJob;
use App\Jobs\UploadTicketJob;
use App\Mail\OrderConfirmMail;
use App\Models\BookingHistory;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\PaymentGateway;
use App\Models\SubTicket;
use App\Models\TicketAddon;
use App\Models\TicketPackage;
use App\Models\User;
use App\Services\BookingService;
use App\Models\TicketSale;
use App\Models\TicketSalePackage;
use App\Services\PromotionService;
use App\Traits\ApiResponse;
use App\Traits\BookingUtils;
use App\Traits\CheckManager;
use App\Traits\GuzzleTrait;
use App\Traits\QrCodeGenerator;
use Exception;
use Hamcrest\Type\IsString;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

class BookingRepository implements BookingService
{

    use BookingUtils, CheckManager, GuzzleTrait, QrCodeGenerator;

    private PromotionService $promoRepository;

    public function __construct(PromotionService $promoRepository)
    {
        $this->promoRepository = $promoRepository;
    }

    public function createBooking(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'integer',
                'event_id' => 'required|integer',
                'first_name' => 'string',
                'last_name' => 'string',
                'email' => 'required|email',
                'phone_no' => 'required|string',
                'ticket_package_details' => 'required',
                'address_line_one' => 'string',
                'city' => 'string',
                'state' => 'string',
                'country' => 'string',
                'zip_code' => 'string',
                'payment_provider' => 'required|string'
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


            $event_id = $request->input('event_id');

            $event = Event::findOrFail($event_id);

            if ($event->status == 'ongoing') {


                $user = User::where('email', $request->input('email'))->orWhere('phone_no', $request->input('phone_no'))->first();
                //$user = $request->user();

                if ($user == null) {
                    $user = User::create([
                        'name' => $request->input('first_name') . " " . $request->input('last_name'),
                        'email' => $request->input('email'),
                        'phone_no' => $request->input('phone_no'),
                        'first_name' => $request->input('first_name'),
                        'nic' => $request->input('nic'),
                        'last_name' => $request->input('last_name'),
                        'password' => Hash::make(explode('@', $request->input('email'))[0])
                    ]);

                    $role = Role::firstOrCreate(['name' => 'User']);
                    $user->assignRole([$role->id]);
                } else {
                    if ($user->hasRole('Manager') || $user->hasRole('Admin')) {
                        throw new Exception("Cannot place bookings using this user account, please use a different account", 400);
                    }

                    if (User::where('email', $request->input('email'))->where('id', '!=', $user->id)->exists()) {
                        throw new Exception("The email is already registered. Please use a different email address.", 400);
                    }

                    if (User::where('phone_no', $request->input('phone_no'))->where('id', '!=', $user->id)->exists()) {
                        throw new Exception("This phone number is already in use. Please provide a different phone number.", 400);
                    }
                }

                $user->update([
                    'first_name' => $request->input('first_name'),
                    'last_name' => $request->input('last_name'),
                    'nic' => $request->input('nic')
                ]);

                $user_id = $user->id;

                $temp_order_id = $this->generateOrderId($event->name);

                $tempOrder = TicketSale::create([
                    'user_id' => $user_id,
                    'event_id' => $event_id,
                    'payment_status' => 'pending',
                    'booking_status' => 'pending',
                    'order_id' => strtoupper($temp_order_id),
                    'transaction_date_time' => \Carbon\Carbon::now(),
                    'payment_method' => $request->input('payment_provider')
                ]);

                $tot_ticket_count = 0;
                $tot_amount = 0;
                $handling_fee = 0;

                foreach ($request->input('ticket_package_details') as $ticket_pack) {
                    $validator = Validator::make($ticket_pack, [
                        'package_id' => 'required',
                        'ticket_count' => 'integer|gt:0|required',
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

                    $selectedTicketPackage = TicketPackage::findOrFail($ticket_pack['package_id']);

                    if ($selectedTicketPackage->max_tickets_can_buy != 0) {
                        if ($user->hasTicketCountAboveForPackage($event->id, $user_id, $selectedTicketPackage->id, $selectedTicketPackage->max_tickets_can_buy, $ticket_pack['ticket_count'])) {
                            throw new Exception("You have reached the maximum number of tickets that a single user is allowed to purchase.", 400);
                        } else if ($ticket_pack['ticket_count'] > $selectedTicketPackage->max_tickets_can_buy) {
                            throw new Exception("You have reached the maximum number of tickets that a single user is allowed to purchase. Please reduce ticket count to " . $selectedTicketPackage->max_tickets_can_buy, 400);
                        }
                    } else {

                        $user->update([
                            'email' => $request->input('email'),
                            'phone_no' => $request->input('phone_no')
                        ]);

                        if (!($ticket_pack['ticket_count'] <= 20)) {
                            throw new Exception("max. 20 tickets can reserve per package per order", 400);
                        }
                    }

                    $promo_id = 0;
                    $promo_response = null;

                    $validator = Validator::make($request->all(), [
                        'promo_code' => 'required'
                    ]);

                    if ($validator->passes()) {

                        $promo_response = $this->promoRepository->checkPromoCodeValidity($request);

                        if ($promo_response != null && $promo_response->status) {
                            $promo_id = $promo_response->data->promo_id;
                        }
                    }

                    $newTicketSalePackage = TicketSalePackage::create([
                        'sale_id' => $tempOrder->id,
                        'package_id' => $ticket_pack['package_id'],
                        'ticket_count' => $ticket_pack['ticket_count'],
                        'seat_nos' => !$selectedTicketPackage->free_seating ? $ticket_pack['seat_nos'] : "",
                        'promo_id' => $promo_id
                    ]);

                    $tot_ticket_count += $ticket_pack['ticket_count'];

                    for ($i = 0; $i < $ticket_pack['ticket_count']; $i++) {
                        $newSubTicket = SubTicket::create([
                            'sale_id' => $tempOrder->id,
                            'package_id' => $newTicketSalePackage->id,
                            'sub_order_id' => $this->generateOrderId($event->name, true)
                        ]);
                    }

                    if ($ticket_pack['ticket_count'] <= $selectedTicketPackage->aval_tickets && $selectedTicketPackage->active && $selectedTicketPackage->aval_tickets > 0) {
                        $reserved_arr = $selectedTicketPackage->reserved_seats;

                        if (!$selectedTicketPackage->free_seating && $ticket_pack['seat_nos'] && sizeof($ticket_pack['seat_nos']) > 0) {

                            $subset = false;
                            count(array_intersect($ticket_pack['seat_nos'], $reserved_arr)) > 0 ? $subset = true : $subset = false;

                            if ($subset)
                                throw new Exception($selectedTicketPackage->name . " package selected seats already reserved", 400);

                            foreach ($ticket_pack['seat_nos'] as $seat) {
                                array_push($reserved_arr, $seat);
                            }
                            $selectedTicketPackage->reserved_seats = $reserved_arr;
                        }

                        $selectedTicketPackage->aval_tickets = $selectedTicketPackage->aval_tickets - $ticket_pack['ticket_count'];

                        $selectedTicketPackage->save();

                        $package_tot_amount = 0;

                        if ($promo_response != null && $promo_response->status) {
                            $package_tot_amount = $promo_response->data->total_due;

                            $tempOrder->comment = "Promo code: " . $promo_response->data->promo_code . " used. " . $promo_response->data->disc_amt . " LKR discount added.";
                            $tempOrder->save();
                        } else {
                            $package_tot_amount = $ticket_pack['ticket_count'] * $selectedTicketPackage->price;
                        }
                        $tot_amount += $package_tot_amount;
                    } else if ($ticket_pack['ticket_count'] > $selectedTicketPackage->aval_tickets && $selectedTicketPackage->aval_tickets > 0 && !$selectedTicketPackage->sold_out) {
                        throw new Exception("Please note that only " . $selectedTicketPackage->aval_tickets . " ticket of " . $selectedTicketPackage->name . ' package is available', 400);
                    } else {
                        throw new Exception($selectedTicketPackage->name . " package tickets sold out", 400);
                    }
                }

                $addons = $request->input('addons');
                if (isset($addons)) {
                    foreach ($addons as $addon) {

                        $validator = Validator::make($addon, [
                            'addon_id' => 'required|integer|gt:0',
                            'quantity' => 'required|integer|gt:0',
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

                        $addonObj = EventAddon::where([
                            ['id', '=', $addon['addon_id']],
                            ['event_id', '=', $event->id]
                        ])->first();

                        if (!isset($addonObj)) {
                            throw new Exception("Requested addon not available", 400);
                        }

                        TicketAddon::create([
                            'sale_id' => $tempOrder->id,
                            'addon_id' => $addonObj->id,
                            'quantity' => $addon['quantity']
                        ]);

                        $tot_amount += $addonObj->price;
                    }
                }

                /*if ($event->handling_cost !== null) {
                    if ($event->handling_cost_perc) {
                        $handling_fee = (($tot_amount * $event->handling_cost) / 100);
                    } else {
                        $handling_fee = $event->handling_cost;
                    }
                }*/

                $payment_provider = PaymentGateway::findOrFail($request->input("payment_provider"));
                $tot_amount = $this->calculateTotalAmount($tot_amount, $event, $payment_provider);

                $tempOrder->tot_ticket_count = $tot_ticket_count;
                $tempOrder->tot_amount = $tot_amount;
                $tempOrder->save();

                DB::commit();

                Log::info("Ticket Booking Create success | " . $temp_order_id);

                $bookingObj = (object) [
                    "order_id" => $temp_order_id,
                    "total_amount" => round($tot_amount, 2),
                    "currency" => $event->currency
                ];

                return (object) [
                    "message" => 'temp order created successfully',
                    "status" => true,
                    "data" => $bookingObj
                ];
            } else {
                throw new Exception("Requested event currently not available", 404);
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Ticket Booking Create Failed | " . $e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => $e->getCode() ? $e->getCode() : 500,
            ];
        }
    }

    private function calculateTotalAmount(float $amount, Event $event, PaymentGateway $payment_provider): float
    {
        if (!isset($event->handling_cost)) {
            return round($amount, 2);
        }

        $handlingFee = 0.0;

        if ($payment_provider->apply_handling_fee) {
            if ($event->handling_cost_perc) {
                $handlingFee = ($amount * floatval($event->handling_cost)) / 100;
            } else {
                $handlingFee = floatval($event->handling_cost);
            }
        }

        $totalAmount = $amount + $handlingFee;

        if ($payment_provider->commission_rate > 0) {
            $totalAmount += ($totalAmount * floatval($payment_provider->commission_rate)) / 100;
        }

        return round($totalAmount, 2);
    }


    public function updateBooking(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'temp_order_id' => 'required|string',
                'transaction_ref' => 'required|string',
                'status_code' => 'required'
            ]);

            if ($validator->fails()) {
                Log::info("Ticket Booking Update failed | " . $validator->messages()->toJson());
                return (object) [
                    "message" => 'validation failed',
                    "status" => false,
                    "errors" => $validator->messages()->toArray(),
                    "code" => 422,
                    "data" => []
                ];
            }

            $temp_order_id = $request->input('temp_order_id');
            Log::info($temp_order_id);

            $ticket_sale = TicketSale::with('packages', 'packages.package', 'addons.addon')->where('order_id', $temp_order_id)->first();

            if ($ticket_sale == null)
                throw new ResourceNotFoundException("Booking cannot find", 404);

            $sub_tickets = SubTicket::where('sale_id', $ticket_sale->id)->with('sale_package', 'sale_package.package')->get();

            if ($sub_tickets == null)
                throw new ResourceNotFoundException("Booking cannot find", 404);

            Log::info($sub_tickets);

            if ($ticket_sale->payment_status == PaymentStatus::PENDING->value) {

                $sale_ticket_packages = $ticket_sale->packages;

                $ticket_sale->payment_ref_no = $request->input('transaction_ref');

                if ($request->input('status_code') == 0) {
                    $ticket_sale->payment_status = PaymentStatus::COMPLETE->value;
                    $ticket_sale->booking_status = BookingStatus::COMPLETE->value;

                    foreach ($sub_tickets as $ticket) {
                        $ticket->booking_status = BookingStatus::COMPLETE->value;
                        $ticket->save();
                    }

                    $user = User::findOrFail($ticket_sale->user_id);

                    $event = Event::with('venue')->findOrFail($ticket_sale->event_id);

                    $sale_ticket_packages = $ticket_sale->packages;

                    $details['email'] = $user->email;
                    $details['tot_amount'] = $request->input('transaction_amount');
                    $details['order_id'] = $ticket_sale->order_id;
                    $details['transaction_date_time'] = $ticket_sale->transaction_date_time;
                    $details['payment_ref_no'] = $request->input('transaction_ref');
                    $details['packages'] = $sale_ticket_packages;
                    $details['tot_ticket_count'] = $ticket_sale->tot_ticket_count;
                    $details['event_uid'] = $event->uid;
                    $details['event_name'] = $event->name;
                    $details['event_venue'] = $event->venue->name;
                    $details['event_date'] = $event->start_date;
                    $details['event_end_date'] = $event->end_date;
                    $details['event_banner'] = $event->thumbnail_img;
                    $details['currency'] = $event->currency;
                    $details['free_seating'] = $event->free_seating;
                    $details['cust_name'] = $user->name;
                    $details['cust_email'] = $user->email;
                    $details['cust_mobile'] = $user->phone_no;
                    $details['cust_id'] = $user->nic;
                    $details['message'] = $this->generateOrderConfirmationSMS($details);
                    $details['S3Path'] = env('AWS_BUCKET_PATH');
                    $details['invitation'] = false;
                    $details['qrCode'] = $this->qrCodeGenerator($ticket_sale->order_id)->getDataUri();
                    $details['addons'] = $ticket_sale->addons;

                    Log::info("Ticket Booking Update | " . $details['message']);

                    Bus::chain([
                        new UploadTicketJob($details),
                        new SendEmailJob($details),
                        new SendSMSJob($details)
                    ])->dispatch();
                } else {
                    $ticket_sale->payment_status = PaymentStatus::FAILED->value;
                    $ticket_sale->booking_status = BookingStatus::FAILED->value;

                    foreach ($sub_tickets as $ticket) {
                        $ticket->booking_status = BookingStatus::FAILED->value;
                        $ticket->save();
                    }

                    foreach ($sale_ticket_packages as $pack) {

                        $ticket_package = TicketPackage::findOrFail($pack->package_id);

                        if ($ticket_sale === null || $ticket_package === null) {
                            throw new ResourceNotFoundException("Ticket package not found", 404);
                        }

                        if (!$ticket_package->free_seating) {
                            $reserved_arr = $ticket_package->reserved_seats;
                            $package_seats = $pack->seat_nos;
                            foreach ($package_seats as $seat) {
                                $index = array_search($seat, $reserved_arr);
                                if ($index !== false) {
                                    array_splice($reserved_arr, $index, 1);
                                }
                            }
                            $ticket_package->reserved_seats = $reserved_arr;
                        }
                        $ticket_package->aval_tickets += $pack->ticket_count;
                        $ticket_package->save();
                    }
                }

                $ticket_sale->tot_amount = $request->input('transaction_amount');
                $ticket_sale->transaction_date_time = \Carbon\Carbon::now();

                $ticket_sale->save();

                DB::commit();

                Log::info("Ticket Booking Update Successful | " . $temp_order_id . " | " . ($request->input('status_code') == 0 ? "complete" : "failed"));

                return (object) [
                    "message" => 'order updated successfully',
                    "status" => $request->input('status_code') == 0 ? true : false,
                    "data" => $ticket_sale->order_id
                ];
            } else {
                return (object) [
                    "message" => 'order updated successfully',
                    "status" => $ticket_sale->payment_status == PaymentStatus::COMPLETE->value ? true : false,
                    "data" => $ticket_sale->order_id
                ];
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Ticket Booking Update Failed | " . $e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => $e->getCode(),
            ];
        }
    }

    public function getAllBookings(Request $request)
    {
        try {

            $query = TicketSale::with('user', 'event', 'packages', 'packages.package', 'addons');

            /*$statuses = $request->input('event_status');

            if ($statuses && sizeof($statuses) > 0) {
                $query = $query->whereIn('status', $request->input('status'));
            }*/

            if ($request->input('user')) {
                $query->where('user_id', $request->input('user'));
            }

            if ($request->input('event_id')) {
                $query->where('event_id', $request->input('event_id'));
            }

            if ($request->input('payment_status')) {
                $query->where('payment_status', $request->input('payment_status'));
            }

            if ($request->filled('event_status')) {
                $query->whereHas('event', function ($q) use ($request) {
                    $q->whereIn('status', $request->input('event_status'));
                });
            }

            if ($request->input('order_id')) {
                $query->where('order_id', 'like', '%' . $request->input('order_id') . '%');
            }

            if ($request->input('payment_ref')) {
                $query->where('payment_ref_no', 'like', '%' . $request->input('payment_ref') . '%');
            }

            if ($request->filled('customer')) {
                $customer = $request->input('customer');
                $query->where(function ($q) use ($customer) {
                    $q->whereRelation('user', 'first_name', 'like', '%' . $customer . '%')
                        ->orWhereRelation('user', 'last_name', 'like', '%' . $customer . '%')
                        ->orWhereRelation('user', 'name', 'like', '%' . $customer . '%')
                        ->orWhereRelation('user', 'nic', 'like', '%' . $customer . '%')
                        ->orWhereRelation('user', 'phone_no', 'like', '%' . $customer . '%')
                        ->orWhereRelation('user', 'email', 'like', '%' . $customer . '%');
                });
            }

            if ($request->filled('date_range')) {
                $dateRange = $request->input('date_range');
                if (is_array($dateRange)) {
                    if (count($dateRange) === 2) {
                        $startDate = Carbon::createFromFormat('m/d/Y', $dateRange[0])->startOfDay();
                        $endDate = Carbon::createFromFormat('m/d/Y', $dateRange[1])->endOfDay();
                        $query->whereBetween('created_at', [$startDate, $endDate]);
                    } elseif (count($dateRange) === 1) {
                        $singleDate = Carbon::createFromFormat('m/d/Y', $dateRange[0])->toDateString();
                        $query->whereDate('created_at', $singleDate);
                    }
                }
            }

            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 0);

            $bookings = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

            $bookings_arr = [];

            foreach ($bookings as $booking) {


                $packages = $booking->packages;

                foreach ($packages as $package) {

                    $book['order_id'] = $booking->order_id;
                    $book['payment_ref_no'] = $booking->payment_ref_no;
                    $book['comment'] = $booking->comment;
                    $book['cust_name'] = $booking->user ? $booking->user->first_name . " " . $booking->user->last_name : null;
                    $book['cust_email'] = $booking->user ? $booking->user->email : null;
                    $book['cust_phone'] = $booking->user ? $booking->user->phone_no : null;
                    $book['cust_nic'] = $booking->user ? $booking->user->nic : null;
                    $book['event_name'] = $booking->event ? $booking->event->name : null;
                    $book['package_name'] = $package->package ? $package->package->name : null;
                    $book['package_tickets'] = $package->ticket_count;
                    $book['package_tot_amount'] = $package->package ? $package->ticket_count * $package->package->price : 0;
                    $book['package_seat_nos'] = $package->seat_nos;
                    $book['tot_amount'] = $booking->tot_amount;
                    $book['currency'] = $booking->event->currency;

                    $verified_ticket_count = SubTicket::join('ticket_sale_packages as tsp', 'tsp.id', '=', 'sub_tickets.package_id')
                        ->where('tsp.id', $package->id)
                        ->where('tsp.package_id', $package->package->id)
                        ->where('sub_tickets.booking_status', 'verified')
                        ->count();

                    $book['tot_verified'] = $verified_ticket_count;
                    $book['tot_available'] = $package->ticket_count - $verified_ticket_count;
                    $book['date'] = Carbon::parse($booking->created_at)->toDateTimeString();
                    $book['payment_status'] = $booking->payment_status;
                    $book['book_status'] = $booking->booking_status;
                    $book['id'] = $booking->id;
                    $book['event_id'] = $booking->event->id;
                    $book['sale_package_id'] = $package->id;
                    $book['payment_method'] = $booking->payment_method;

                    array_push($bookings_arr, $book);
                }
            }

            $bookingObj = (object) [
                'data' => $bookings_arr,
                'current_page' => $bookings->currentPage(),
                'total' => $bookings->total()
            ];
            return (object) [
                "message" => 'bookings retrieved successfully',
                "status" => true,
                "data" => $bookingObj
            ];
        } catch (Exception $e) {
            Log::error("bookings retreived Failed | " . $e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getBooking(Request $request, $id)
    {
        try {
            $booking = TicketSale::with(['user', 'event', 'packages', 'packages.package', 'addons.addon'])
                ->where('order_id', $id)
                ->whereHas('event', function ($query) {
                    $query->whereIn('status', [EventStatus::ONGOING->value, EventStatus::SOLDOUT->value, EventStatus::CLOSED->value]);
                })
                ->when($request->input('customer_nic'), function ($query, $customer_nic) {
                    $query->whereHas('user', function ($query) use ($customer_nic) {
                        $query->where('nic', $customer_nic);
                    });
                })
                ->where(function ($query) {
                    $query->whereIn('booking_status', [BookingStatus::COMPLETE->value, BookingStatus::FAILED->value])
                        ->whereIn('payment_status', [PaymentStatus::COMPLETE->value, PaymentStatus::FAILED->value]);
                })
                ->firstOrFail();

            $packages = $booking->packages;

            [$startDate, $startTime] = explode(" ", $booking->event->start_date);

            return (object) [
                "message" => 'booking retrieved successfully',
                "status" => true,
                "data" => [
                    'id' => $booking->id,
                    'order_id' => $booking->order_id,
                    'payment_ref_no' => $booking->payment_ref_no,
                    'cust_name' => $booking->user->name ?? null,
                    'cust_email' => $booking->user->email ?? null,
                    'cust_phone' => $booking->user->phone_no ?? null,
                    'cust_nic' => $booking->user->nic ?? null,
                    'event_name' => $booking->event->name ?? null,
                    'event_id' => $booking->event->id ?? null,
                    'event_venue' => $booking->event->venue->name ?? null,
                    'event_thumb_img' => $booking->event->thumbnail_img ?? null,
                    'event_start_date' => $startDate,
                    'event_start_time' => $startTime,
                    'packages' => $packages,
                    'tot_amount' => $booking->tot_amount,
                    'tot_tickets' => $booking->tot_ticket_count,
                    'date' => $booking->created_at->toDateTimeString(),
                    'status' => $booking->payment_status,
                    'book_status' => $booking->booking_status,
                    'ticket_url' => $booking->e_ticket_url,
                    'addons' => $booking->addons
                ],
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

    /*public function verifyBooking(Request $request)
    {
        $orderId = $request->input("orderId");
        Log::error($orderId);
        try {

            $order = TicketSale::with('user', 'event', 'packages.package', 'sub_bookings.sale_package.package', 'addons.addon')->where("order_id", $orderId)->first();

            if (!$order) {
                throw new ResourceNotFoundException("Invalid Order ID");
            } else if ($order->event->status === EventStatus::COMPLETE->value) {
                throw new ResourceNotFoundException("Invalid Order ID");
            }

            $eventStartDate = Carbon::parse($order->event->start_date);
            $eventEndDate = Carbon::parse($order->event->end_date);
            $currentDate = Carbon::now();

            $isCurrentDateInRange = $currentDate->between($eventStartDate, $eventEndDate, true);

            if (!$this->checkManager($order->event->id) && !(Auth::user()->hasRole('Admin'))) {
                return (object) [
                    "message" => 'Ticket cannot verify.',
                    "status" => false,
                    "code" => 401
                ];
            } else if (!$isCurrentDateInRange) {
                return (object) [
                    "message" => 'Ticket cannot verify. This event is not scheduled for today.',
                    "status" => true,
                    "code" => 405
                ];
            }

            $order_obj['order_id'] = $order->order_id;
            $order_obj['payment_ref_no'] = $order->payment_ref_no;
            $order_obj['cust_name'] = $order->user->first_name . " " . $order->user->last_name;
            $order_obj['cust_email'] = $order->user->email;
            $order_obj['cust_nic'] = $order->user->nic;
            $order_obj['cust_phone'] = $order->user->phone_no;
            $order_obj['cust_mobile'] = $order->user->phone_no;
            $order_obj['event'] = $order->event->name;
            $order_obj['currency'] = $order->event->currency;
            $order_obj['tot_tickets'] = $order->tot_ticket_count;

            $order_obj['sub_bookings'] = $order->sub_bookings;

            $package_arr = [];
            foreach ($order->packages as $package) {
                $tick_package = $package->package;
                $package_obj['name'] = $tick_package->name;
                $package_obj['price'] = $tick_package->price;
                $package_obj['ticket_count'] = $package->ticket_count;

                array_push($package_arr, $package_obj);
            }

            $order_obj['packages'] = $package_arr;

            $order_obj['status'] = $order->booking_status;

            $ticket_addons_arr = [];
            foreach ($order->addons as $addon) {
                $tick_addon = $addon->addon;
                $addon_obj['name'] = $tick_addon->name;
                $addon_obj['price'] = $tick_addon->price;
                $addon_obj['category'] = $tick_addon->category;
                $addon_obj['image_url'] = $addon->image_url;
                $addon_obj['quantity'] = $addon->quantity;

                array_push($ticket_addons_arr, $addon_obj);
            }
            $order_obj['addons'] = $ticket_addons_arr;

            if ($order->payment_status == PaymentStatus::COMPLETE->value && $order->booking_status == BookingStatus::COMPLETE->value || $order->payment_status == PaymentStatus::PARTIALLY_VERIFIED->value && $order->booking_status == BookingStatus::PARTIALLY_VERIFIED->value) {


                $order->verified_by = Auth::user()->id;
                $order->verified_at = $currentDate;

                $order_obj['verified_by'] = User::findOrFail(Auth::user()->id)->name;
                $order_obj['verified_at'] = $currentDate;


                $order->booking_status = PaymentStatus::VERIFIED->value;
                $order->payment_status = BookingStatus::VERIFIED->value;
                $order->tot_verified_ticket_count = $order->tot_ticket_count;

                foreach ($order->sub_bookings as $booking) {
                    $booking->booking_status = BookingStatus::VERIFIED->value;
                    $booking->save();
                }

                $order->save();

                $order_obj['tot_verified_tickets'] = $order->tot_ticket_count;
                $order_obj['status'] = BookingStatus::VERIFIED->value;
                $order_obj['message'] = $this->generateOrderVerificationSMS($order_obj);

                Bus::chain([
                    new SendSMSJob($order_obj)
                ])->dispatch();

                return (object) [
                    "message" => 'Booking verified successfully. ' . $order->tot_ticket_count . ' ticket verified.',
                    "status" => true,
                    "data" => $order_obj,
                ];
            } else if ($order->payment_status == PaymentStatus::VERIFIED->value && $order->booking_status == BookingStatus::VERIFIED->value) {
                $order_obj['verified_by'] = $order->verified_by != null ? User::findOrFail($order->verified_by)->name : null;
                $order_obj['verified_at'] = $order->verified_at;
                return (object) [
                    "message" => 'Ticket already verified',
                    "status" => true,
                    "data" => $order_obj,
                    "code" => 400
                ];
            } else {
                return (object) [
                    "message" => 'Payment status failed, booking cannot verify',
                    "status" => false,
                    "data" => $order_obj,
                    "code" => 400
                ];
            }
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }*/

    public function verifyBooking(Request $request)
    {
        $orderId = $request->input("orderId");

        try {

            $order = TicketSale::with('user', 'event', 'packages.package', 'sub_bookings.sale_package.package', 'addons.addon', 'history')->where("order_id", $orderId)->first();

            if (!$order) {
                throw new ResourceNotFoundException("Invalid Order ID");
            } else if ($order->event->status === EventStatus::COMPLETE->value) {
                throw new ResourceNotFoundException("Invalid Order ID");
            }

            $eventStartDate = Carbon::parse($order->event->start_date);
            $eventEndDate = Carbon::parse($order->event->end_date);
            $currentDate = Carbon::now();

            $isCurrentDateInRange = $currentDate->between($eventStartDate, $eventEndDate, true);

            if (!$this->checkManager($order->event->id) && !$this->checkCoordinator($order->event->id) && !(Auth::user()->hasRole('Admin'))) {
                return (object) [
                    "message" => 'Insufficient permissions to verify ticket',
                    "status" => false,
                    "code" => 401
                ];
            } else if (!$isCurrentDateInRange) {
                return (object) [
                    "message" => 'Ticket cannot verify. This event is not scheduled for today.',
                    "status" => true,
                    "code" => 405
                ];
            }

            $order_obj['order_id'] = $order->order_id;
            $order_obj['payment_ref_no'] = $order->payment_ref_no;
            $order_obj['cust_name'] = $order->user->first_name . " " . $order->user->last_name;
            $order_obj['cust_email'] = $order->user->email;
            $order_obj['cust_nic'] = $order->user->nic;
            $order_obj['cust_phone'] = $order->user->phone_no;
            $order_obj['cust_mobile'] = $order->user->phone_no;
            $order_obj['event'] = $order->event->name;
            $order_obj['currency'] = $order->event->currency;
            $order_obj['tot_tickets'] = $order->tot_ticket_count;
            $order_obj['invitation'] = strpos($order->order_id, 'INV') !== false;
            $order_obj['sub_bookings'] = $order->sub_bookings;

            $package_arr = [];
            foreach ($order->packages as $package) {
                $tick_package = $package->package;
                $package_obj['name'] = $tick_package->name;
                $package_obj['price'] = $tick_package->price;
                $package_obj['ticket_count'] = $package->ticket_count;

                array_push($package_arr, $package_obj);
            }

            $order_obj['packages'] = $package_arr;

            $order_obj['status'] = $order->booking_status;

            $ticket_addons_arr = [];
            foreach ($order->addons as $addon) {
                $tick_addon = $addon->addon;
                $addon_obj['name'] = $tick_addon->name;
                $addon_obj['price'] = $tick_addon->price;
                $addon_obj['category'] = $tick_addon->category;
                $addon_obj['image_url'] = $addon->image_url;
                $addon_obj['quantity'] = $addon->quantity;

                array_push($ticket_addons_arr, $addon_obj);
            }
            $order_obj['addons'] = $ticket_addons_arr;

            $history_arr = [];
            foreach ($order->history as $history) {
                $history_arr[] = [
                    'verified_at' => $history->data['verified_at'] ?? null,
                    'data' => array_diff_key($history->data, ['verified_at' => '']),
                ];
            }
            $order_obj['history'] = $history_arr;

            if ($order->payment_status == PaymentStatus::COMPLETE->value && $order->booking_status == BookingStatus::COMPLETE->value || $order->payment_status == PaymentStatus::PARTIALLY_VERIFIED->value && $order->booking_status == BookingStatus::PARTIALLY_VERIFIED->value) {


                $order->verified_by = Auth::user()->id;
                $order->verified_at = $currentDate;

                $order_obj['verified_by'] = User::findOrFail(Auth::user()->id)->name;
                $order_obj['verified_at'] = $currentDate;

                if ($order->tot_ticket_count === 1) {
                    $order->booking_status = PaymentStatus::VERIFIED->value;
                    $order->payment_status = BookingStatus::VERIFIED->value;
                    $order->tot_verified_ticket_count = $order->tot_ticket_count;

                    foreach ($order->sub_bookings as $booking) {
                        $booking->booking_status = BookingStatus::VERIFIED->value;
                        $booking->save();
                    }

                    $order->save();

                    $order_obj['verified_packages'] = $package_arr;
                    $order_obj['tot_verified_tickets'] = $order->tot_ticket_count;
                    $order_obj['status'] = BookingStatus::VERIFIED->value;
                    $order_obj['message'] = $this->generateOrderVerificationSMS($order_obj);

                    $history_obj = (object) [
                        'verified_at' => $currentDate,
                        'packages' => $package_arr
                    ];

                    BookingHistory::create([
                        'sale_id' => $order->id,
                        'data' => $history_obj
                    ]);

                    $history_arr = [];

                    foreach ($order->history as $history) {
                        $history_arr[] = [
                            'verified_at' => $history->data['verified_at'] ?? null,
                            'data' => array_diff_key($history->data, ['verified_at' => '']),
                        ];
                    }

                    $order_obj['history'] = $history_arr;

                    if (config('app.country') === "LK") {
                        Bus::chain([
                            new SendSMSJob($order_obj)
                        ])->dispatch();
                    }
                    return (object) [
                        "message" => 'Booking verified successfully. ' . $order->tot_ticket_count . ' ticket verified.',
                        "status" => true,
                        "data" => $order_obj,
                    ];
                }

                if ($request->has("verified_tickets")) {
                    $verified_tickets = $request->input("verified_tickets");
                    $verified_tickets_count = sizeof($verified_tickets);
                    if (($order->payment_status == PaymentStatus::COMPLETE->value && $order->booking_status == BookingStatus::COMPLETE->value) || ($order->payment_status == PaymentStatus::PARTIALLY_VERIFIED->value && $order->booking_status == BookingStatus::PARTIALLY_VERIFIED->value)) {

                        $package_arr = [];
                        $package_exists = false;

                        // Update sub-bookings status
                        foreach ($order->sub_bookings as $booking) {
                            $bookingId = $booking->sub_order_id;
                            if (in_array($bookingId, $verified_tickets)) {
                                $tick_package = $booking->sale_package->package;
                                foreach ($package_arr as &$existing_package) {
                                    if ($existing_package['name'] === $tick_package->name) {
                                        $package_exists = true;
                                        $existing_package['ticket_count']++;
                                        break;
                                    }
                                }

                                if (!$package_exists) {
                                    $package_obj['name'] = $tick_package->name;
                                    $package_obj['price'] = $tick_package->price;
                                    $package_obj['ticket_count'] = 1;

                                    array_push($package_arr, $package_obj);
                                }

                                $booking->verified_by = Auth::user()->id;
                                $booking->verified_at = $currentDate;

                                $booking->booking_status = BookingStatus::VERIFIED->value;
                                $booking->save();
                            }
                        }

                        $order_obj['verified_packages'] = $package_arr;

                        // Check if all tickets are verified
                        $allTicketsVerified = $order->sub_bookings()->whereIn('sub_order_id', $verified_tickets)->count() == $order->tot_ticket_count;

                        $subTickets = SubTicket::with('ticket_sale')->where('sale_id', $order->id)->get();
                        $allVerified = $subTickets->every(function ($item) {
                            return $item->booking_status === BookingStatus::VERIFIED->value;
                        });

                        // Update order status
                        if ($allVerified) {
                            $order->booking_status = BookingStatus::VERIFIED->value;
                            $order->payment_status = PaymentStatus::VERIFIED->value;

                            $order->tot_verified_ticket_count = $order->tot_ticket_count;

                            $order_obj['tot_verified_tickets'] = $order->tot_ticket_count;
                            $order_obj['status'] = BookingStatus::VERIFIED->value;
                            $order_obj['message'] = $this->generateOrderVerificationSMS($order_obj);

                            if (config('app.country') === "LK") {
                                Bus::chain([
                                    new SendSMSJob($order_obj)
                                ])->dispatch();
                            }

                            $order->save();

                            $history_obj = (object) [
                                'verified_at' => $currentDate,
                                'packages' => $package_arr
                            ];

                            BookingHistory::create([
                                'sale_id' => $order->id,
                                'data' => $history_obj
                            ]);

                            $history_arr = [];

                            foreach ($order->history as $history) {
                                $history_arr[] = [
                                    'verified_at' => $history->data['verified_at'] ?? null,
                                    'data' => array_diff_key($history->data, ['verified_at' => '']),
                                ];
                            }

                            $order_obj['history'] = $history_arr;

                            return (object) [
                                "message" => 'Booking verified successfully. ' . $order->tot_ticket_count . ' ticket(s) verified.',
                                "status" => true,
                                "data" => $order_obj,
                            ];
                        } else {
                            $order->booking_status = PaymentStatus::PARTIALLY_VERIFIED->value;
                            $order->payment_status = BookingStatus::PARTIALLY_VERIFIED->value;

                            $order->tot_verified_ticket_count = $order->tot_verified_ticket_count + $verified_tickets_count;

                            $order_obj['tot_verified_tickets'] = $order->tot_verified_ticket_count + $verified_tickets_count;
                            $order_obj['status'] = BookingStatus::PARTIALLY_VERIFIED->value;

                            $order->save();

                            $history_obj = (object) [
                                'verified_at' => $currentDate,
                                'packages' => $package_arr
                            ];

                            BookingHistory::create([
                                'sale_id' => $order->id,
                                'data' => $history_obj
                            ]);

                            $history_arr = [];

                            foreach ($order->history as $history) {
                                $history_arr[] = [
                                    'verified_at' => $history->data['verified_at'] ?? null,
                                    'data' => array_diff_key($history->data, ['verified_at' => '']),
                                ];
                            }

                            $order_obj['history'] = $history_arr;

                            return (object) [
                                "message" => 'Booking partially verified successfully. ' . $verified_tickets_count . ' ticket(s) verified',
                                "status" => true,
                                "data" => $order_obj,
                            ];
                        }
                    }
                }
            } else if ($order->payment_status == PaymentStatus::VERIFIED->value && $order->booking_status == BookingStatus::VERIFIED->value) {
                $order_obj['verified_by'] = $order->verified_by != null ? User::findOrFail($order->verified_by)->name : null;
                $order_obj['verified_at'] = $order->verified_at;
                $order_obj['verified_packages'] = $package_arr;

                return (object) [
                    "message" => 'Ticket already verified',
                    "status" => true,
                    "data" => $order_obj,
                    "code" => 400
                ];
            } else {
                return (object) [
                    "message" => 'Payment status failed, booking cannot verify',
                    "status" => false,
                    "data" => $order_obj,
                    "code" => 400
                ];
            }

            $order_obj['status'] = BookingStatus::COMPLETE->value;
            return (object) [
                "message" => 'booking verification data retrieved successfully',
                "status" => true,
                "data" => $order_obj,
            ];
        } catch (Exception $e) {
            Log::error($e);
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function updateBookingData(Request $request, $id)
    {
        Log::channel('admin-actions')->info("Manual booking update initiated by: " . Auth::user()->name . " for Booking #" . $request->input('order_id'));

        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|string',
                'status' => 'required|string',
                'cust_mail' => 'required|string',
                'cust_phone' => 'required',
                'sale_package_id' => 'required'
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

            // get order id from input bag
            $order_id = $request->input('order_id');

            // get seats nos from input bag
            $selected_seats = $request->input('seat_nos');

            $generate_ticket = $request->input('generate_ticket');

            // query Ticket Sale record from database
            $ticket_sale = TicketSale::with('event', 'user', 'sub_bookings')->where('order_id', $order_id)->first();

            // if Ticket Sale record not found throw error
            if ($ticket_sale == null) {
                Log::channel('admin-actions')->info("Booking entry for Booking #" . $request->input('order_id') . " not found");
                throw new ResourceNotFoundException("Booking cannot find");
            }

            if ($ticket_sale->payment_status == 'pending' || $request->input('status') == 'pending') {
                throw new Exception("Booking is in pending state cannot proceed.");
            }

            // event data from booking
            $event = $ticket_sale->event;

            // query Ticket Sales Package record from database
            $ticket_sale_package = TicketSalePackage::findOrFail($request->input('sale_package_id'));

            // query Ticket Package record from database
            $ticket_package = TicketPackage::findOrFail($ticket_sale_package->package_id);

            // get User record from booking
            $user = $ticket_sale->user;

            $seats_arr = $ticket_sale_package->seat_nos;

            if ($selected_seats && is_string($selected_seats))
                $seats_arr = explode(",", $selected_seats);

            Log::channel('admin-actions')->info("Booking status change request to " . $request->input('status') . " was " . $ticket_sale->payment_status);

            // check updated status is not equal to current booking status
            if ($request->input('status') != $ticket_sale->payment_status) {

                if (($request->input('status') == BookingStatus::COMPLETE->value || $request->input('status') == 'verified') && ($ticket_sale->payment_status == 'cancelled' || $ticket_sale->payment_status == 'failed' || $ticket_sale->payment_status == 'refunded')) {
                    if ($ticket_package->aval_tickets < $ticket_sale_package->ticket_count)
                        throw new Exception("Please reduce " . $ticket_package->name . " package ticket count to " . $ticket_package->aval_tickets, 400);

                    // check the package is not free seating, seats nos input filled and it's a string
                    if (!$ticket_package->free_seating) {
                        $reserved_arr = $ticket_package->reserved_seats; // get reserved seats from package
                        $seat_nos_arr = $ticket_sale_package->seat_nos; // get reserved seats from booking

                        Log::channel('admin-actions')->info("reserved_arr " . implode(",", $reserved_arr));
                        Log::channel('admin-actions')->info("seat_nos_arr " . implode(",", $seat_nos_arr));

                        $reserved_arr_subset = false;
                        $seat_nos_arr_subset = false;

                        // check updated seats nos is a subset of reserved tickets array of package
                        count(array_intersect($seats_arr, $reserved_arr)) > 0 ? $reserved_arr_subset = true : $reserved_arr_subset = false;

                        // check updated seats nos is a subset of reserved tickets array of booking
                        count(array_intersect($seats_arr, $seat_nos_arr)) > 0 ? $seat_nos_arr_subset = true : $seat_nos_arr_subset = false;


                        Log::channel('admin-actions')->info("seat_nos_arr_subset " . $seat_nos_arr_subset);
                        Log::channel('admin-actions')->info("reserved_arr_subset " . $reserved_arr_subset);

                        if (!$seat_nos_arr_subset && !$reserved_arr_subset) {

                            foreach ($seat_nos_arr as $seat) {
                                $index = array_search($seat, $reserved_arr);

                                if ($index !== false) {
                                    array_splice($reserved_arr, $index, 1);
                                }
                            }

                            foreach ($seats_arr as $seat) {
                                array_push($reserved_arr, $seat);
                            }
                        } else if ($seat_nos_arr_subset) {

                            $new_seats = array_diff($seats_arr, $seat_nos_arr);

                            if (count($new_seats) == 0) {
                                $new_seats = $seats_arr;
                            }

                            $res_new_seats = array_intersect($new_seats, $reserved_arr);

                            $is_reserved = count($res_new_seats) > 0;

                            if ($is_reserved) {
                                Log::channel('admin-actions')->info(implode(",", $res_new_seats) . " seat(s) already reserved");
                                throw new Exception(implode(",", $res_new_seats) . " seat(s) already reserved", 400);
                            }

                            foreach ($new_seats as $seat) {
                                array_push($reserved_arr, $seat);
                                array_push($seat_nos_arr, $seat);
                            }
                        } else {
                            Log::channel('admin-actions')->info(implode(",", $seats_arr) . " seat(s) already reserved");
                            throw new Exception(implode(",", $seats_arr) . " seat(s) already reserved", 400);
                        }

                        $ticket_package->reserved_seats = $reserved_arr;
                        $ticket_sale_package->seat_nos = $seat_nos_arr;


                        Log::channel('admin-actions')->info(implode(",", $seat_nos_arr) . " seat no(s) reserved");
                        Log::channel('admin-actions')->info(implode(",", $reserved_arr) . " seat(s) reserved in package");
                    }

                    $ticket_package->aval_tickets -= $ticket_sale_package->ticket_count;

                    Log::info("Deducting " . $ticket_sale_package->ticket_count . " tickets from " . $ticket_package->name . " from Order ID: " . $ticket_sale->order_id);
                    Log::channel('admin-actions')->info("Deducting " . $ticket_sale_package->ticket_count . " tickets from " . $ticket_package->name . " from Order ID: " . $ticket_sale->order_id);
                } else if (($request->input('status') == 'cancelled' || $request->input('status') == 'failed' || $request->input('status') == 'refunded') && ($ticket_sale->payment_status == 'complete' || $ticket_sale->payment_status == 'verified')) {

                    if (!$ticket_package->free_seating) {
                        $reserved_arr = $ticket_package->reserved_seats; // get reserved seats from package
                        $seat_nos_arr = $ticket_sale_package->seat_nos; // get reserved seats from booking

                        foreach ($seat_nos_arr as $seat) {
                            $index = array_search($seat, $reserved_arr);

                            if ($index !== false) {
                                array_splice($reserved_arr, $index, 1);
                            }
                        }

                        $ticket_package->reserved_seats = $reserved_arr;
                    }

                    $ticket_package->aval_tickets += $ticket_sale_package->ticket_count;

                    Log::info("Adding " . $ticket_sale_package->ticket_count . " tickets to " . $ticket_package->name . " from Order ID: " . $ticket_sale->order_id);
                    Log::channel('admin-actions')->info("Adding " . $ticket_sale_package->ticket_count . " tickets to " . $ticket_package->name . " from Order ID: " . $ticket_sale->order_id);
                } else if ($request->input('status') == BookingStatus::COMPLETE->value && $ticket_sale->payment_status == BookingStatus::VERIFIED->value) {
                    $ticket_sale->tot_verified_ticket_count = 0;
                }
            } else {
                // check the package is not free seating, seats nos input filled and it's a string
                if (!$ticket_package->free_seating && $selected_seats && is_string($selected_seats)) {

                    // convert string to seats nos array
                    $seats_arr = explode(",", $selected_seats);

                    $reserved_arr = $ticket_package->reserved_seats; // get reserved seats from package
                    $seat_nos_arr = $ticket_sale_package->seat_nos; // get reserved seats from booking

                    $reserved_arr_subset = false;
                    $seat_nos_arr_subset = false;

                    // check updated seats nos is a subset of reserved tickets array of package
                    count(array_intersect($seats_arr, $reserved_arr)) > 0 ? $reserved_arr_subset = true : $reserved_arr_subset = false;

                    // check updated seats nos is a subset of reserved tickets array of booking
                    count(array_intersect($seats_arr, $seat_nos_arr)) > 0 ? $seat_nos_arr_subset = true : $seat_nos_arr_subset = false;

                    if (!$seat_nos_arr_subset && !$reserved_arr_subset) {

                        foreach ($seat_nos_arr as $seat) {
                            $index = array_search($seat, $reserved_arr);

                            if ($index !== false) {
                                array_splice($reserved_arr, $index, 1);
                            }
                        }

                        foreach ($seats_arr as $seat) {
                            array_push($reserved_arr, $seat);
                        }
                    } else if ($seat_nos_arr_subset) {

                        $new_seats = array_diff($seats_arr, $seat_nos_arr);

                        $res_new_seats = array_intersect($new_seats, $reserved_arr);

                        $is_reserved = count($res_new_seats) > 0;

                        if ($is_reserved) {
                            Log::channel('admin-actions')->error(implode(",", $res_new_seats) . " seat(s) already reserved");
                            throw new Exception(implode(",", $res_new_seats) . " seat(s) already reserved", 400);
                        }

                        foreach ($new_seats as $seat) {
                            array_push($reserved_arr, $seat);
                            array_push($seat_nos_arr, $seat);
                        }
                    } else {
                        Log::channel('admin-actions')->error(implode(",", $seats_arr) . " seat(s) already reserved");
                        throw new Exception(implode(",", $seats_arr) . " seat(s) already reserved", 400);
                    }

                    $ticket_package->reserved_seats = $reserved_arr;
                    $ticket_sale_package->seat_nos = $seat_nos_arr;
                }
            }

            $ticket_package->save();
            $ticket_sale_package->save();

            $ticket_sale->payment_ref_no = $request->input('payment_ref');
            $ticket_sale->tot_amount = $request->input('payment_amount');
            $ticket_sale->payment_status = $request->input('status');
            $ticket_sale->booking_status = $request->input('status');
            $ticket_sale->comment = $request->input('comment');

            foreach ($ticket_sale->sub_bookings as $sub_booking) {
                $sub_booking->booking_status = $request->input('status');
                $sub_booking->save();
            }

            $ticket_sale->save();

            $user->email = $request->input('cust_mail');
            $user->phone_no = $request->input('cust_phone');
            $user->save();

            if ($generate_ticket) {
                Log::channel('admin-actions')->info("E-ticket generation requested");
                $details['email'] = $user->email;
                $details['order_id'] = $ticket_sale->order_id;
                $details['transaction_date_time'] = $ticket_sale->transaction_date_time;
                $details['tot_amount'] = $ticket_sale->tot_amount;
                $details['payment_ref_no'] = $ticket_sale->payment_ref_no;
                $details['packages'] = $ticket_sale->packages;
                $details['tot_ticket_count'] = $ticket_sale->tot_ticket_count;
                $details['event_name'] = $event->name;
                $details['event_venue'] = $event->venue->name;
                $details['event_date'] = $event->start_date;
                $details['free_seating'] = $event->free_seating;
                $details['cust_mobile'] = $user->phone_no;
                $details['message'] = $this->generateOrderConfirmationSMS($details);
                $details['S3Path'] = env('AWS_BUCKET_PATH');
                $details['invitation'] = false;

                Bus::chain([
                    new UploadTicketJob($details)
                ])->dispatch();
            }

            DB::commit();

            Log::channel('admin-actions')->info("Ticket Booking Update Successful by " . Auth::user()->name . " | " . $order_id);
            return (object) [
                "message" => 'order updated successfully',
                "status" => true,
                "data" => $ticket_sale->order_id
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Ticket Booking Update Failed | " . $e);
            Log::channel('admin-actions')->error("Ticket Booking Update Failed by " . Auth::user()->name . " | " . $order_id . " | " . $e);
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function generateETicket($booking_id)
    {
        try {
            // Retrieve ticket sale with relationships
            $ticket_sale = TicketSale::with('user', 'packages.package', 'event', 'addons.addon')->where('order_id', $booking_id)->first();

            if (!$ticket_sale) {
                throw new ResourceNotFoundException("Booking not found", 404);
            }

            // Check if the payment status is complete or verified and event status is not complete
            if (in_array($ticket_sale->payment_status, [PaymentStatus::COMPLETE->value, PaymentStatus::VERIFIED->value]) && $ticket_sale->event->status != EventStatus::COMPLETE->value) {

                // Retrieve user and event details
                $user = User::findOrFail($ticket_sale->user_id);
                $event = Event::with('venue')->findOrFail($ticket_sale->event_id);

                // Prepare details for the e-ticket
                $details = [
                    'email' => $user->email,
                    'tot_amount' => $ticket_sale->tot_amount,
                    'order_id' => $ticket_sale->order_id,
                    'transaction_date_time' => $ticket_sale->transaction_date_time,
                    'payment_ref_no' => $ticket_sale->payment_ref_no,
                    'packages' => $ticket_sale->packages,
                    'tot_ticket_count' => $ticket_sale->tot_ticket_count,
                    'event_name' => $event->name,
                    'event_venue' => $event->venue->name,
                    'event_date' => $event->start_date,
                    'event_banner' => $event->banner_img,
                    'currency' => $event->currency,
                    'free_seating' => $event->free_seating,
                    'cust_name' => $user->name,
                    'cust_mobile' => $user->phone_no,
                    'cust_email' => $user->email,
                    'cust_id' => $user->nic,
                    'message' => $this->generateOrderConfirmationSMS($ticket_sale),
                    'S3Path' => env('AWS_BUCKET_PATH'),
                    'qrCode' => $this->qrCodeGenerator($ticket_sale->order_id)->getDataUri(),
                    'invitation' => strpos($ticket_sale->order_id, 'INV') !== false,
                    'addons' => $ticket_sale->addons
                ];

                // Log QR code and dispatch the job
                Log::info("Dispatching UploadTicketJob for Order ID: " . $ticket_sale->order_id);
                UploadTicketJob::dispatchSync($details);

                return (object) [
                    "message" => 'E-ticket generation queued successfully',
                    "status" => true,
                    "data" => $ticket_sale->order_id
                ];
            }

            // If conditions are not met, return an error response
            return (object) [
                "message" => 'Requested booking is expired',
                "status" => false,
                "data" => $booking_id,
                "code" => 400
            ];
        } catch (ResourceNotFoundException $e) {
            // Specific catch for ResourceNotFoundException
            Log::error("E-ticket generation failed | " . $e->getMessage());
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404
            ];
        } catch (Exception $e) {
            // General catch for any other exceptions
            Log::error("E-ticket generation failed | " . $e->getMessage());
            return (object) [
                "message" => $e->getMessage() ?: "Something went wrong",
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500
            ];
        }
    }

    public function generateSubBookings($booking_id)
    {
        try {
            // Retrieve ticket sale with relationships
            $ticket_sale = TicketSale::with('user', 'packages.package', 'event', 'sub_bookings')->where('order_id', $booking_id)->first();

            if (!$ticket_sale) {
                throw new ResourceNotFoundException("Booking not found", 404);
            }

            // Check if the payment status is complete or verified and event status is not complete
            if (in_array($ticket_sale->payment_status, [PaymentStatus::COMPLETE->value, PaymentStatus::VERIFIED->value]) && $ticket_sale->event->status != EventStatus::COMPLETE->value) {

                if (sizeof($ticket_sale->sub_bookings) == 0) {
                    $ticketSalePackages = $ticket_sale->packages;

                    $isInvitation = strpos($ticket_sale->order_id, 'INV') !== false;

                    foreach ($ticketSalePackages as $ticketSalePackage) {
                        for ($i = 0; $i < $ticketSalePackage->ticket_count; $i++) {
                            $newSubTicket = SubTicket::create([
                                'sale_id' => $ticket_sale->id,
                                'package_id' => $ticketSalePackage->id,
                                'sub_order_id' => $this->generateOrderId($ticket_sale->event->name, true, null, $isInvitation),
                                'booking_status' => 'complete'
                            ]);
                        }
                    }

                    Log::info("Creating Sub bookings for Order ID: " . $ticket_sale->order_id);

                    return (object) [
                        "message" => 'Sub-bookings generated successfully',
                        "status" => true,
                        "data" => $ticket_sale->order_id
                    ];
                } else {
                    return (object) [
                        "message" => 'Sub-bookings already exist',
                        "status" => true,
                        "data" => $ticket_sale->order_id
                    ];
                }
            }

            // If conditions are not met, return an error response
            return (object) [
                "message" => 'Requested booking is expired',
                "status" => false,
                "data" => $booking_id,
                "code" => 400
            ];
        } catch (ResourceNotFoundException $e) {
            // Specific catch for ResourceNotFoundException
            Log::error("Sub-bookings generation failed | " . $e->getMessage());
            return (object) [
                "message" => $e->getMessage(),
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404
            ];
        } catch (Exception $e) {
            // General catch for any other exceptions
            Log::error("Sub-bookings generation failed | " . $e->getMessage());
            return (object) [
                "message" => $e->getMessage() ?: "Something went wrong",
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500
            ];
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $ticket_sale = TicketSale::with('event', 'packages', 'packages.package')->where('order_id', $id)->first();

            if ($ticket_sale == null)
                throw new ResourceNotFoundException("Booking cannot find", 404);

            $sub_tickets = SubTicket::where('sale_id', $ticket_sale->id)->with('sale_package', 'sale_package.package')->get();

            if ($sub_tickets == null)
                throw new ResourceNotFoundException("Booking cannot find", 404);

            $ticket_sale->payment_status = $request->input("status");
            $ticket_sale->booking_status = $request->input("status");

            foreach ($sub_tickets as $ticket) {
                $ticket->booking_status = $request->input("status");
                $ticket->save();
            }

            $ticket_sale->save();

            return (object) [
                "message" => 'booking status updated',
                "status" => true,
                "data" => $ticket_sale->order_id,
                "code" => 200
            ];
        } catch (Exception $e) {
            Log::error("E-ticket generation failed | " . $e->getMessage());
            return (object) [
                "message" => $e->getMessage() ?: "Something went wrong",
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500
            ];
        }
    }
}
