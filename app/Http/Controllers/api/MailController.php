<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Jobs\SendSMSJob;
use App\Models\TicketSale;
use App\Traits\ApiResponse;
use App\Traits\BookingUtils;
use App\Traits\QrCodeGenerator;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class MailController extends Controller
{
    use ApiResponse, BookingUtils, QrCodeGenerator;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function sendOrderMail(Request $request)
    {
        try{
            $orderId = $request->input("order_id");

            $ticket_sale = TicketSale::with('user', 'event', 'packages', 'packages.package')->where('order_id', $orderId)->first();

            if($ticket_sale == null) {
                throw new ResourceNotFoundException("Order id is incorrect");
            }

            $sale_ticket_packages = $ticket_sale->packages;
    
            $user = $ticket_sale->user;
    
            $event = $ticket_sale->event;
    
            $details['email'] = $user->email;
            $details['cc_email'] = $request->input("ccMail");
            $details['order_id'] = $ticket_sale->order_id;
            $details['transaction_date_time'] = $ticket_sale->transaction_date_time;
            $details['tot_amount'] = $ticket_sale->tot_amount;
            $details['payment_ref_no'] = $ticket_sale->payment_ref_no;
            $details['packages']  = $sale_ticket_packages;
            $details['tot_ticket_count']  = $ticket_sale->tot_ticket_count;
            $details['event_uid'] = $event->uid;
            $details['event_name']  = $event->name;
            $details['event_venue']  = $event->venue->name;
            $details['event_date']  = $event->start_date;
            $details['event_end_date']  = $event->end_date;
            $details['event_banner']  = $event->banner_img;
            $details['event_manager'] = $event->organizer;
            $details['currency']  = $event->currency;
            $details['free_seating']  = $event->free_seating;
            $details['cust_name'] = $user->name;
            $details['cust_mobile'] = $user->phone_no;
            $details['cust_id'] = $user->nic;
            $details['cust_email'] = $user->email;
            $details['message'] = $this->generateOrderConfirmationSMS($details);
            $details['S3Path'] = env('AWS_BUCKET_PATH');
            $details['qrCode'] = $this->qrCodeGenerator($ticket_sale->order_id)->getDataUri();

            if(strpos($orderId, 'INV') !== false) {
                $details['tot_amount'] =  "INVITATION";
                $details['payment_ref_no'] = "INVITATION";
                $details['invitation'] = true;
                Bus::chain([
                    new SendEmailJob($details)
                ])->dispatch();
            } else {
                $details['invitation'] = false;
                Bus::chain([
                    new SendEmailJob($details),
                    //new SendSMSJob($details)
                ])->dispatch();
            }          

            Log::info("Sending Order Email to | " . $user->email . " | Order ID: " . $orderId);

            $response = (object) [
                "message" => 'Order mail sent successfully',
                "status" => true,
                "data" => []
            ];

            return $this->generateResponse($response);

        } catch(Exception $err) {
            Log::error("Ticket Booking Update Failed | " . $err);
            $response = (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $err->getMessage(),
                "code" => 500,
            ];
            return $this->generateResponse($response);
        }
        
    }
}
