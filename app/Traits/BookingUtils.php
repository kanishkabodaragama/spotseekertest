<?php

namespace App\Traits;

trait BookingUtils
{

    /**
     * Generate booking confirmation SMS text
     */
    public function generateOrderConfirmationSMS($details)
    {
        $msg_upper = 'Congratulations!%0a%0d';

        $msg_upper =  $msg_upper . 'Your spot for '. $details['event_name'] .' is reserved.%0a%0d';
        $msg_upper =  $msg_upper . 'Booking ID: ' . $details['order_id'] . '%0a%0d';

        // Optionally, you can re-enable the ticket details if needed.
        /* 
        $msg_upper =  $msg_upper . 'Ticket Details:%0a%0d';

        foreach($details['packages'] as $package) {
            $msg_upper .=  'Category: ' . $package['package']['name'] . ' x ' . $package['ticket_count'] . '%0a%0d';
        }

        $msg_upper = $msg_upper . '%0a%0dTotal tickets: ' . $details['tot_ticket_count'] . '%0a%0d%0a%0d';
        */

        //$msg_upper = $msg_upper . 'Your e-ticket (with QR code) has been sent to your email. Please present it at the venue.%0a%0d';

        $msg_upper = $msg_upper . 'Download E-ticket: https://spotseeker.lk/checkout/' . $details['event_uid']. '/success?order_id='. $details['order_id'];
        
        //$msg_upper = $msg_upper . 'Team Spotseeker!';

        return urlencode($msg_upper);

    }

    /**
     * Generate booking verification confirm SMS text
     */
    public function generateOrderVerificationSMS($details)
    {
        $msg_upper = 'Hi there,%0a%0d';

        $msg_upper =  $msg_upper . 'Your tickets for ' . $details['event'] .' have been successfully redeemed.%0a%0d%0a%0d';
        $msg_upper =  $msg_upper . 'Booking ID: ' . $details['order_id'] . '%0a%0d%0a%0d';
        $msg_upper = $msg_upper . 'Thank you for choosing SpotSeeker.lk!%0a%0d';

        return urlencode($msg_upper);

    }

    /**
     * Generate booking id
     */
    public function generateOrderId($event_name, $is_sub = false, $sub_order_no = null, $is_invitation = false)
    {

        $event_words = explode(" ", $event_name);
        $event_acronym = "";

        for ($i = 0; $i < sizeof($event_words); $i++) {
            if (preg_match("/^[a-zA-Z]+$/", $event_words[$i]) == 1) {
                if ($i != 3) {
                    $event_acronym .= mb_substr($event_words[$i], 0, 1);
                } else {
                    break;
                }
            }
        }

        $random = random_int(100, 999);
        $event_acronym .= $random;

        if ($is_sub) {
            $event_acronym = $event_acronym . "sub" . $sub_order_no;
        }

        if($is_invitation) {
            $event_acronym = $event_acronym . "INVITE";
        }

        return uniqid($event_acronym, $is_sub);
    }
}
