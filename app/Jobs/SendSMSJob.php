<?php

namespace App\Jobs;

use App\Traits\GuzzleTrait;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, GuzzleTrait;

    protected $details;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff()
    {
        return [10, 20, 30];
    }

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
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        $MSISDN = $this->details["cust_mobile"];
        $SRC = config('sms.src');
        $MESSAGE = (urldecode($this->details["message"]));
        $AUTH = config('sms.auth');

        $response = $this->guzzleRequest("https://send.lk/sms/send.php?to={$MSISDN}&from={$SRC}&message={$MESSAGE}&token={$AUTH}");

        Log::info("Sending Ticket Booking SMS to: " . $MSISDN . " Message: " . $MESSAGE);

        if ($response) {
            Log::info("Ticket Booking SMS Sent: " . (string) $response->getBody());
        } else {
            Log::error("Ticket Booking SMS Error");
        }
    }
}
