<?php

namespace App\Repositories;

use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\CheckManager;
use Illuminate\Support\Facades\Log;

class SalesRepository implements SalesService
{

    use CheckManager;

    public function getSalesData(Request $request)
{
    // Load all necessary relationships including promotions
    $events = Event::with([
        'ticket_packages', 
        'venue', 
        'managerr', 
        'addons',
        'ticket_sales' => function ($query) {
            $query->whereIn('payment_status', [
                PaymentStatus::COMPLETE->value,
                PaymentStatus::VERIFIED->value,
                PaymentStatus::PARTIALLY_VERIFIED->value
            ]);
        },
        'ticket_sales.packages',  // Load the ticket sale packages
        'ticket_sales.packages.promotion',  // Load the promotions
        'ticket_sales.packages.package'  // Load the package details
    ])
    ->where('status', '!=', 'pending')
    ->orderBy('featured', 'DESC')
    ->orderBy('created_at', 'DESC')
    ->orderBy('start_date', 'ASC')
    ->get();

    $sales_arr = [];
    
    foreach ($events as $event) {
        // Debug log to check event sales
        Log::info("Processing event: {$event->id} with " . count($event->ticket_sales) . " sales");
        
        foreach ($event->ticket_packages as $package) {
            $obj = [
                'event_id' => $event->id,
                'event_name' => $event->name,
                'package' => $package->name,
                'package_price' => $package->price,
                'tot_tickets' => $package->tot_tickets,
                'res_tickets' => $package->res_tickets
            ];

            // Get sold tickets count
            $soldTicketsQuery = DB::table('ticket_sales')
                ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->where([
                    ['ticket_sales.event_id', '=', $event->id],
                    ['ticket_sale_packages.package_id', '=', $package->id],
                    ['ticket_sales.deleted_at', '=', null]
                ])
                ->whereIn('ticket_sales.payment_status', [
                    PaymentStatus::COMPLETE->value,
                    PaymentStatus::VERIFIED->value,
                    PaymentStatus::PARTIALLY_VERIFIED->value
                ]);

            $sold_tickets = $soldTicketsQuery->sum('ticket_sale_packages.ticket_count');

            $obj['sold_tickets'] = $sold_tickets ?: 0;
            $obj['aval_tickets'] = $package->tot_tickets - $obj['sold_tickets'] - $package->res_tickets;
            $obj['organizer'] = $event->managerr ? $event->managerr->name : 'N/A';
            $obj['res_seats'] = $package->reserved_seats;
            $obj['unavail_seats'] = $package->available_seats;
            $obj['free_seating'] = $package->free_seating;

            // Initialize promotion tracking
            $packagePromoAmount = 0;
            $promoDetails = [];

            // Get all sales for this package with their promotions
            $packageSales = DB::table('ticket_sales')
                ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->join('promotions', 'ticket_sale_packages.promo_id', '=', 'promotions.id')
                ->select(
                    'ticket_sales.id',
                    'ticket_sales.tot_amount',
                    'ticket_sale_packages.ticket_count',
                    'promotions.id as promo_id',
                    'promotions.coupon_code as promo_name',
                    'promotions.discount_amount',
                    'promotions.percentage',
                    'promotions.per_ticket'
                )
                ->where([
                    ['ticket_sales.event_id', '=', $event->id],
                    ['ticket_sale_packages.package_id', '=', $package->id],
                    ['ticket_sales.deleted_at', '=', null]
                ])
                ->whereIn('ticket_sales.payment_status', [
                    PaymentStatus::COMPLETE->value,
                    PaymentStatus::VERIFIED->value,
                    PaymentStatus::PARTIALLY_VERIFIED->value
                ])
                ->whereNotNull('ticket_sale_packages.promo_id')
                ->get();

            // Debug log for promotions
            Log::info("Package {$package->id} has " . count($packageSales) . " sales with promotions");

            foreach ($packageSales as $sale) {
                $promoAmount = 0;

                if ($sale->per_ticket) {
                    if ($sale->percentage) {
                        $package_discount = $package->price * ($sale->discount_amount / 100);
                        $promoAmount = $package_discount * $sale->ticket_count;
                    } else {
                        $promoAmount = $sale->discount_amount * $sale->ticket_count;
                    }
                } else {
                    if ($sale->percentage) {
                        $promoAmount = $sale->tot_amount * ($sale->discount_amount / 100);
                    } else {
                        $promoAmount = $sale->discount_amount;
                    }
                }

                $packagePromoAmount += $promoAmount;

                if (!isset($promoDetails[$sale->promo_id])) {
                    $promoDetails[$sale->promo_id] = [
                        'name' => $sale->promo_name,
                        'total_discount' => 0,
                        'tickets_count' => 0,
                        'type' => $sale->percentage ? 'percentage' : 'fixed',
                        'amount' => $sale->discount_amount,
                        'per_ticket' => $sale->per_ticket
                    ];
                }

                $promoDetails[$sale->promo_id]['total_discount'] += $promoAmount;
                $promoDetails[$sale->promo_id]['tickets_count'] += $sale->ticket_count;
            }

            $obj['total_promo_amount'] = $packagePromoAmount;
            $obj['promotions'] = array_values($promoDetails);

            // Add addons information
            $addons_arr = [];
            foreach ($event->addons as $addon) {
                $addon_sales = DB::table('ticket_addons')
                    ->join('ticket_sales', 'ticket_addons.sale_id', '=', 'ticket_sales.id')
                    ->where([
                        ['ticket_sales.event_id', '=', $event->id],
                        ['ticket_addons.addon_id', '=', $addon->id],
                        ['ticket_sales.deleted_at', '=', null]
                    ])
                    ->whereIn('ticket_sales.payment_status', [
                        PaymentStatus::COMPLETE->value,
                        PaymentStatus::VERIFIED->value,
                        PaymentStatus::PARTIALLY_VERIFIED->value
                    ])
                    ->sum('ticket_addons.quantity');

                $addons_arr[] = [
                    'addon_name' => $addon->name,
                    'addon_price' => $addon->price,
                    'total_sold' => $addon_sales,
                ];
            }
            
            $obj['addons'] = $addons_arr;
            $sales_arr[] = $obj;
        }
    }

    return (object) [
        "message" => 'sales retrieved successfully',
        "status" => true,
        "data" => $sales_arr
    ];
}

    public function getManagerSalesData()
    {

        if (!$this->checkManager(Auth::user()->id)) {
            return (object) [
                "message" => 'Requested event not found.',
                "status" => false,
                "code" => 401
            ];
        }

        $events = Event::with('ticket_packages', 'venue', 'managerr', 'addons')->where([
            ['manager', '=', Auth::user()->id]
        ])->orderBy('featured', 'DESC')->orderBy('created_at', 'DESC')->orderBy('start_date', 'ASC')->get();

        $sales_arr = [];
        foreach ($events as $event) {
            foreach ($event->ticket_packages as $package) {

                if (!$package->private) {
                    $obj['event_id'] = $event->id;
                    $obj['event_name'] = $event->name;
                    $obj['package'] = $package->name;
                    $obj['package_price'] = $package->price;
                    $obj['tot_tickets'] = $package->tot_tickets;
                    $pending_ticket_count = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where([
                            ['ticket_sales.event_id', $event->id],
                            ['ticket_sales.payment_status', 'pending'],
                            ['ticket_sale_packages.package_id', $package->id],
                        ])
                        ->pluck('ticket_count')[0];

                    $obj['res_tickets'] = $package->res_tickets;
                    $sold_tickets = DB::table('ticket_sales')
                        ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                        ->select(DB::raw('SUM(ticket_sale_packages.ticket_count) as ticket_count'))
                        ->where('ticket_sales.event_id', $event->id)
                        ->where('ticket_sale_packages.package_id', $package->id)
                        ->whereIn('ticket_sales.payment_status', [
                            PaymentStatus::COMPLETE->value,
                            PaymentStatus::VERIFIED->value,
                            PaymentStatus::PARTIALLY_VERIFIED->value
                        ])
                        ->pluck('ticket_count')
                        ->first();

                    $obj['aval_tickets'] = $package->tot_tickets - (int)$sold_tickets - $package->res_tickets;
                    $obj['sold_tickets'] = $sold_tickets;
                    $obj['organizer'] = $event->managerr->name;
                    $obj['res_seats'] = $package->reserved_seats;
                    $obj['unavail_seats'] = $package->available_seats;
                    $obj['free_seating'] = $package->free_seating;

                    $addons_arr = [];
                    foreach ($event->addons as $addon) {
                        $addon_sales = DB::table('ticket_addons')
                            ->join('ticket_sales', 'ticket_addons.sale_id', '=', 'ticket_sales.id')
                            ->where('ticket_sales.event_id', $event->id)
                            ->where('ticket_addons.addon_id', $addon->id)
                            ->whereIn('ticket_sales.payment_status', [
                                PaymentStatus::COMPLETE->value,
                                PaymentStatus::VERIFIED->value,
                                PaymentStatus::PARTIALLY_VERIFIED->value
                            ])
                            ->sum('ticket_addons.quantity');

                        array_push($addons_arr, [
                            'addon_name' => $addon->name,
                            'addon_price' => $addon->price,
                            'total_sold' => $addon_sales,
                        ]);
                    }

                    $obj['addons'] = $addons_arr;

                    array_push($sales_arr, $obj);
                }
            }
        }

        return (object) [
            "message" => 'sales retrieved successfully',
            "status" => true,
            "data" => $sales_arr
        ];
    }
}
