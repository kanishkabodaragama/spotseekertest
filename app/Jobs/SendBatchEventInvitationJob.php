<?php

namespace App\Jobs;

use App\Mail\EventInvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Resend;

class SendBatchEventInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invitations;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff()
    {
        return [300, 600, 900];
    }

    /**
     * Create a new job instance.
     *
     * @param array $invitations Array of invitation details
     * @return void
     */
    public function __construct(array $invitations)
    {
        $this->invitations = $invitations;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $resend = Resend::client(env('RESEND_API_KEY'));
            
            // Prepare batch email array
            $batchEmails = [];
            
            foreach ($this->invitations as $details) {
                // Render the email view
                $htmlContent = view('emails.eventInvitation', [
                    'data' => $details,
                    'type' => 'mail'
                ])->render();
                
                // Generate PDF for attachment
                $pdfView = 'pdf.orderPDF';
                $ticketPdfName = $details["order_id"] . ".pdf";
                
                $pdf = \Barryvdh\Snappy\Facades\SnappyPdf::loadView($pdfView, ['data' => $details, 'type' => "pdf"])
                    ->setPaper('A4')
                    ->setOption('margin-top', 0)
                    ->setOption('margin-bottom', 0)
                    ->setOption('margin-left', 0)
                    ->setOption('margin-right', 0)
                    ->setOption('no-outline', true)
                    ->setOption('disable-smart-shrinking', true);
                
                $pdfContent = $pdf->output();
                
                // Build email structure for batch API
                $emailData = [
                    'from' => $details['event_manager'] . ' <invitations@spotseeker.lk>',
                    'to' => [$details['email']],
                    'subject' => "You are invited to " . $details['event_name'],
                    'html' => $htmlContent,
                    'attachments' => [
                        [
                            'filename' => $ticketPdfName,
                            'content' => base64_encode($pdfContent),
                        ]
                    ]
                ];
                
                // Add CC if provided
                if (isset($details['cc_email'])) {
                    $emailData['cc'] = [$details['cc_email']];
                }
                
                $batchEmails[] = $emailData;
                
                Log::info("Prepared invitation email for batch | user ID: " . $details['user_id'] . " | event ID: " . $details['event_id']);
            }
            
            // Send batch emails using Resend
            $response = $resend->batch->send($batchEmails);
            
            // Log success
            Log::info("Batch invitation emails sent successfully", [
                'batch_size' => count($batchEmails),
                'response' => $response
            ]);
            
            // Check for errors in response (permissive mode)
            if (isset($response['errors']) && !empty($response['errors'])) {
                Log::warning("Some emails in batch failed", [
                    'errors' => $response['errors']
                ]);
            }
            
        } catch (\Exception $err) {
            Log::error("Batch invitation emails failed", [
                'error' => $err->getMessage(),
                'trace' => $err->getTraceAsString(),
                'batch_size' => count($this->invitations)
            ]);
            throw $err; // Re-throw to trigger retry mechanism
        }
    }
}
