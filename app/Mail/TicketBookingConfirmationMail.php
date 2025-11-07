<?php

namespace App\Mail;

use DateTime;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Spatie\CalendarLinks\Link;

class TicketBookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $details;

    /**
     * Create a new job instance.
     *
     * @param array $details
     */
    public function __construct(array $details)
    {
        $this->details = $details;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $address = "bookings@spotseeker.lk";
        $subject = 'Ticket Booking Confirmation';
        $fromName = "SpotSeeker";
        $view = 'emails.ticketBookingConfirmation';
        $orderId = $this->details['order_id'];

        try {
            $mail = $this->subject($subject)
                ->view($view, ['data' => $this->details, 'type' => "mail"])
                ->from($address, $fromName);

            Log::info("Order mail compose success | Order ID: $orderId");

            return $mail;
        } catch (Exception $err) {
            Log::error("Order mail compose failed | Order ID: $orderId | Error: " . $err->getMessage());
            throw $err; // Optionally rethrow if you want the job to retry
        }
    }
}
