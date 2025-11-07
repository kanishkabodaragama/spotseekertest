<?php

namespace App\Traits;

trait NotifyUtils
{

    /**
     * Generate custom SMS text
     */
    public function generateGeneralSMS()
    {
        $msg_upper = 'Hi there,%0a%0d';
        $msg_upper = $msg_upper . 'Thank you for choosing SpotSeeker.lk!%0a%0d';

        return urlencode($msg_upper);

    }

    public function generateCustomSMS(string $recipientName, string $messageBody, ?string $senderName = null): string
    {
        // Basic SMS format with recipient's name
        $formattedMessage = "Hello";
        
        if ($recipientName) {
            $formattedMessage .= " {$recipientName},\n\n";
        } else {
            $formattedMessage .= ",\n\n";
        }

        $formattedMessage .= $messageBody;

        if ($senderName) {
            $formattedMessage .= "\n\n- {$senderName}";
        }

        return urlencode($formattedMessage);
    }
}
