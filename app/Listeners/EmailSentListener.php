<?php

namespace App\Listeners;

use App\Mail\EventInvitationMail;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class EmailSentListener implements ShouldQueue
{
    use InteractsWithQueue;
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle($event)
    {
        // Extract the Mailable instance
        $mailable = $event->data;

        // Ensure it's an instance of your Mailable class
        if ($mailable instanceof EventInvitationMail) {
            $details = $mailable->details;

            // Now you have access to the details
            $eventId = $details['event_id'];

            // Perform your resource update logic here
            $this->updateResourceBasedOnEmailData($eventId);
        }
    }

    /**
     * Update resources based on email data.
     *
     * @param  string  $emailData
     * @return void
     */
    protected function updateResourceBasedOnEmailData($eventId)
    {
        // Implement your resource update logic here
        // Example: Update a user record or create a log entry

        // Assuming you have a User model and you are updating a status
        $event = Event::findOrFail($eventId);
        if ($event) {
            $event->invitation_count -= 1;
            $event->save();
        }

        // Log the event
        Log::info('Invitation email was sent successfully.', ['event' => $event->name]);
    }
}
