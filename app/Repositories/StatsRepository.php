<?php

namespace App\Repositories;

use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\TicketSale;
use App\Services\PaymentService;
use App\Services\StatsService;
use App\Traits\WebXPayApi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsRepository implements StatsService
{

    public function getBasicStats(Request $request)
    {
        config()->set('database.connections.mysql.strict', false);

        $on_going_events_count = Event::where('status', 'ongoing')->count();
        $up_coming_events_count = Event::where('status', 'pending')->count();
        $sold_out_events_count = Event::where('status', 'soldout')->count();
        $closed_events_count = Event::where('status', 'closed')->count();
        $postponed_events_count = Event::where('status', 'postponed')->count();
        $completed_events_count = Event::where('status', 'complete')->count();

        $most_upcoming_event = Event::with('venue')->orderBy('start_date', 'ASC')->where('status', 'pending')->first();

        $on_going_events = Event::with('ticket_sales')
            ->where('status', 'ongoing')
            ->orWhere('status', 'soldout')
            ->orWhere('status', 'closed')
            ->orWhere('status', 'postponed')
            ->withSum(
                ['ticket_sales' => function ($query) {
                    $query->where('payment_status', PaymentStatus::COMPLETE->value)->orWhere('payment_status', PaymentStatus::VERIFIED->value)->orWhere('payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                }],
                'tot_amount'
            )
            ->withSum(
                ['ticket_sales' => function ($query) {
                    $query->where('payment_status', PaymentStatus::COMPLETE->value)->orWhere('payment_status', PaymentStatus::VERIFIED->value)->orWhere('payment_status', PaymentStatus::PARTIALLY_VERIFIED->value);
                }],
                'tot_ticket_count'
            )
            ->get();

        /*$on_going_events_sales = TicketSale::with('event')
        ->whereHas('event', function ($query) {
            return $query->where('status', '=', 'ongoing');
        })
        ->get()
        ->groupBy(function($item) {
            return [$item->created_at->format('Y-m-d')];
        });*/

        /*$on_going_events_sales = TicketSale::select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(tot_amount) as tot_amount'), DB::raw('SUM(tot_ticket_count) as tot_ticket_count'))
        ->orderBy('date')
        ->groupBy('date')
	    ->get();*/

        $events_ticket_sales = array();

        $events_tot_ticket_sales_LKR = 0;
        $events_tot_ticket_sales_USD = 0;

        foreach ($on_going_events as $event) {
            $obj = [
                "event_id" => $event->id,
                "event_name" => $event->name . " (" . ($event->ticket_sales_sum_tot_ticket_count ?? 0) . "-Tickets)",
                "tot_sale" => number_format($event->ticket_sales_sum_tot_amount, 2, '.', '')
            ];

            if ($event->currency == "LKR") {
                $events_tot_ticket_sales_LKR += $event->ticket_sales_sum_tot_amount;
            } else if ($event->currency == "USD") {
                $events_tot_ticket_sales_USD += $event->ticket_sales_sum_tot_amount;
            }

            array_push($events_ticket_sales, $obj);
        }

        /*$sales_by_date = TicketSale::with('packages.package')->select(DB::raw("SUM(tot_ticket_count) as ticket_count"), DB::raw("(SUM(tot_ticket_count) * packages.package.price) as tickets_sale"), DB::raw("DATE(created_at) as date"))
        ->where('payment_status', 'complete')
        ->groupBy('event_id','date')
        ->pluck('ticket_count', 'date');*/

        $sales_by_date = cache()->remember('sales_by_date', 5 * 60, function () {
            return DB::table('ticket_sales')
                ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
                ->join('ticket_packages', 'ticket_sale_packages.package_id', '=', 'ticket_packages.id')
                ->selectRaw('SUM(DISTINCT ticket_sales.id) as unique_sales_count, SUM(tot_amount) as tickets_sale, DATE(ticket_sales.created_at) as date')
                ->whereIn('ticket_sales.payment_status', ['complete', 'verified'])
                ->whereIn('ticket_sales.booking_status', ['complete', 'verified'])
                ->where('ticket_sales.created_at', '>', now()->subDays(30)->endOfDay()->toDateString()) // Last 30 days
                ->groupBy('date')
                ->pluck('tickets_sale', 'date');
        });

        //dd($event_ticket_sales);

        /*dd( (object) [
            "message" => 'events retreived successfully',
            "status" => true,
            "data" => (object) [
                "on_going_events" => $on_going_events_count,
                "up_coming_events" => $up_coming_events_count,
                "pre_sale_events" => $pre_sale_events_count,
                "completed_events" => $completed_events_count,
                "most_upcoming_event" => $most_upcoming_event,
                "events_ticket_sales" => $events_ticket_sales
            ],
        ]);*/

        return (object) [
            "message" => 'events retreived successfully',
            "status" => true,
            "data" => (object) [
                "on_going_events" => $on_going_events_count,
                "up_coming_events" => $up_coming_events_count,
                "completed_events" => $completed_events_count,
                'sold_out_events' => $sold_out_events_count,
                'closed_events' => $closed_events_count,
                'postponed_events' => $postponed_events_count,
                "most_upcoming_event" => $most_upcoming_event,
                "events_ticket_sales" => $events_ticket_sales,
                "events_tot_ticket_sales_LKR" => number_format($events_tot_ticket_sales_LKR, 2),
                "events_tot_ticket_sales_USD" => number_format($events_tot_ticket_sales_USD, 2),
                "sales_by_date" => $sales_by_date
            ],
        ];
    }
}
