<?php

namespace App\Mail;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExceptionOccured extends Mailable
{
    use Queueable, SerializesModels;

    public $content;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($content)
    {
        $this->content = $content;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        try {
            $address = "system@spotseeker.lk";
    
            $this->subject("SpotSeeker System(" . env('APP_ENV') . ")")
                ->view('emails.exception', ['content' => $this->content])
                ->from($address);

            return $this;
        } catch(Exception $e) {
            Log::error("Exception mail compose failed");
        }
    }
}
