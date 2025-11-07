<?php

namespace App\Mail;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Barryvdh\Snappy\Facades\SnappyPdf;

class EventInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $details;
   
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($details)
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

        try {    
            $address = "invitations@spotseeker.lk";
    
            $mail = $this->subject("You are invited to " . $this->details['event_name'])
                ->view('emails.eventInvitation', ['data' => $this->details, 'type' => "mail"])
                ->from($address, $this->details['event_manager']);

                $pdfView = 'pdf.orderPDF';
            $ticket_pdf_name = $this->details["order_id"] . ".pdf";

            $pdf = SnappyPdf::loadView($pdfView, ['data' => $this->details, 'type' => "pdf"])
            ->setPaper('A4')
            ->setOption('margin-top', 0)
            ->setOption('margin-bottom', 0)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0)
            ->setOption('no-outline', true)
            ->setOption('disable-smart-shrinking', true);
                
                $mail->attachData($pdf->output(), $ticket_pdf_name);

            Log::info("Invitation mail compose success | user ID: " . $this->details['user_id'] . "| event ID: " . $this->details['event_id']);
            return $this;
        } catch(Exception $err) {
            Log::error("Invitation mail compose failed | user ID: " . $this->details['user_id'] . "| event ID: " . $this->details['event_id']);
        }
        
    }
}
