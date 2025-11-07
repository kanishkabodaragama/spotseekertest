<?php

namespace App\Jobs;

use App\Models\TicketSale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Barryvdh\Snappy\Facades\SnappyPdf;

class UploadTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $details;

    /**
     * Number of seconds to wait before retrying the job.
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
     * @param array $details
     * @return void
     */
    public function __construct(array $details)
    {
        $this->details = $details;
    }

    /**
     * Handle job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception)
    {
        Log::error('UploadTicketJob failed for Order ID: ' . $this->details['order_id'] . ' | Exception: ' . $exception->getMessage());
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Validate details array
            $this->validateDetails($this->details);

            // Generate PDF
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

            // Upload PDF to S3
            $s3Path = $this->details['S3Path'] . "/tickets/" . $ticket_pdf_name;
            Storage::disk('s3')->put($s3Path, $pdf->output());

            // Get URL of the uploaded PDF
            $ticket_pdf_url = Storage::disk('s3')->url($s3Path);

            // Update the TicketSale record
            $booking = TicketSale::where('order_id', $this->details["order_id"])->firstOrFail();
            $booking->e_ticket_url = $ticket_pdf_url;
            $booking->save();

            Log::info($this->details["order_id"] . ' E-Ticket PDF Uploaded successfully: ' . $ticket_pdf_url);
        } catch (Exception $e) {
            Log::error('UploadTicketJob encountered an error | ' . $e->getMessage());
            $this->fail($e); // Explicitly mark job as failed
        }
    }

    /**
     * Validate the details array.
     *
     * @param array $details
     * @return void
     * @throws Exception
     */
    protected function validateDetails(array $details)
    {
        $requiredKeys = ['order_id', 'S3Path'];
        foreach ($requiredKeys as $key) {
            if (empty($details[$key])) {
                throw new Exception("Missing required detail: $key");
            }
        }
    }
}
