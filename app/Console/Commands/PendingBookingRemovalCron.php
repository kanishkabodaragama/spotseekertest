<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\TicketPackage;
use App\Models\TicketSale;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PendingBookingRemovalCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbr:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will remove pending booking tickets based on created time';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            DB::beginTransaction();
        
            $pending_bookings = TicketSale::with('sub_bookings')->where([
                ['payment_status', '=', 'pending'],
                ['booking_status', '=', 'pending'],
                ['created_at', '<', Carbon::now()->subMinute(15)->toDateTimeString()],
            ])->with('packages')->get();
        
            Log::info("Pending Bookings found: " . $pending_bookings->count());
            Log::channel('pending-removal-cron')->info("Pending Bookings found: " . $pending_bookings->count());

            foreach ($pending_bookings as $booking) {
                Log::channel('pending-removal-cron')->info("Pending Bookings removal started for: " . $booking->order_id);
                $ticket_sale_packages = $booking->packages;
        
                foreach ($ticket_sale_packages as $ticket_sale_package) {
                    try {
                        $package = TicketPackage::findOrFail($ticket_sale_package->package_id);
                        Log::channel('pending-removal-cron')->info("Avilable tickets in package: " . $ticket_sale_package->package_id . " - " . $ticket_sale_package->ticket_count);
                        if ($package->active) {
                            $package->aval_tickets += $ticket_sale_package->ticket_count;
                            Log::channel('pending-removal-cron')->info("Avilable tickets after in package: " . $ticket_sale_package->package_id . " - " . $package->aval_tickets);
                        
                            if (!$package->free_seating) {
                                $reserved_arr = $package->reserved_seats;
                                $package_seats = $ticket_sale_package->seat_nos;
        
                                foreach ($package_seats as $seat) {
                                    $index = array_search($seat, $reserved_arr);
        
                                    if ($index !== false) {
                                        array_splice($reserved_arr, $index, 1);
                                    }
                                }
        
                                $package->reserved_seats = $reserved_arr;
                            }
        
                            Log::info("Adding " . $ticket_sale_package->ticket_count . " tickets to " . $package->name . " from Order ID: " . $booking->order_id);
                            Log::channel('pending-removal-cron')->info("Adding " . $ticket_sale_package->ticket_count . " tickets to " . $package->name . " from Order ID: " . $booking->order_id);
                            $package->save();
                        } else {
                            Log::error("Ticket Package already sold out. Skipping");
                            Log::channel('pending-removal-cron')->error("Ticket Package already sold out. Skipping");
                        }
                    } catch (ModelNotFoundException $e) {
                        Log::error("Ticket Package not found: " . $e->getMessage());
                        Log::channel('pending-removal-cron')->error("Ticket Package not found: " . $e->getMessage());
                    }
                }
        
                $booking->payment_status = PaymentStatus::CANCELLED->value;
                $booking->booking_status = BookingStatus::CANCELLED->value;

                foreach($booking->sub_bookings as $sub_booking) {
                    $sub_booking->booking_status = BookingStatus::CANCELLED->value;
                    $sub_booking->save();
                }
                
                $booking->save();
            }
        
            DB::commit();
            
        } catch (QueryException $e) {
            DB::rollBack();
            Log::error("Database query error: " . $e->getMessage());
            Log::channel('pending-removal-cron')->error("Database query error: " . $e->getMessage());
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Pending Booking Removal Cron Error: " . $e->getMessage());
            Log::channel('pending-removal-cron')->error("Pending Booking Removal Cron Error: " . $e->getMessage());
        }

        return 0;
    }
}
