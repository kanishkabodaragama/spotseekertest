<?php

namespace App\Helpers;

use App\Jobs\SendSMSJob;
use App\Traits\GuzzleTrait;
use Fouladgar\MobileVerification\Contracts\SMSClient;
use Fouladgar\MobileVerification\Notifications\Messages\Payload;
use Illuminate\Support\Facades\Log;

class _SMSClient implements SMSClient
{
    use GuzzleTrait;

    protected $SMSService;

    /**
     * @param Payload $payload
     *
     * @return mixed
     */
    public function sendMessage(Payload $payload):mixed
    {
        $otp = $payload->getToken();
        $message = "Verification code: {$otp}\n Enter this code to complete the process.";

        $details = [
            'cust_mobile' => $payload->getTo(),
            'message' => urlencode($message),
        ];

        $MSISDN = $details["cust_mobile"];
        $SRC = config('sms.src');
        $MESSAGE = (urldecode($details["message"]));
        $AUTH = config('sms.auth');

        /*$options = [
            'verify' => storage_path('cacert.pem') // Adjust path to where you store cacert.pem
        ];*/
    
        $response = $this->guzzleRequest("https://send.lk/sms/send.php?to={$MSISDN}&from={$SRC}&message={$MESSAGE}&token={$AUTH}");

        if ($response) {
            Log::info("mobile verification SMS Sent: " . (string) $response->getBody());
        } else {
            Log::error("mobile verification SMS Error");
        }

        return $response;
    }
}