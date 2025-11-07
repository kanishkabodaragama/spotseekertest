<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Jobs\SendSMSJob;
use App\Models\TicketSale;
use App\Traits\ApiResponse;
use App\Traits\BookingUtils;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class JobsController extends Controller
{
    use ApiResponse, BookingUtils;

    public function getJobs() {
        try {
            $failed_jobs = DB::table('failed_jobs')->get();
            $queued_jobs = DB::table('jobs')->get();

            $jobs_arr = [];

            foreach($failed_jobs as $job) {
                $obj['uuid'] = $job->uuid;
                $obj['queue'] = $job->queue;
                $obj['connection'] = $job->connection;
                $obj['failed_at'] = $job->failed_at;
                $obj['payload'] = $job->payload;
                $obj['exception'] = $job->exception;
                $obj['status'] = "failed";

                array_push($jobs_arr, $obj);
            }

            foreach($queued_jobs as $job) {
                $obj['uuid'] = $job->id;
                $obj['queue'] = $job->queue;
                $obj['connection'] = "database";
                $obj['failed_at'] = $job->created_at;
                $obj['payload'] = $job->payload;
                $obj['exception'] = $job->payload;
                $obj['status'] = "queued";

                array_push($jobs_arr, $obj);
            }


            $response = (object) [
                "message" => 'jobs retrieved successfully',
                "status" => true,
                "data" => $jobs_arr
            ];
    
            return $this->generateResponse($response);
        } catch(Exception $err) {
            $response = (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $err->getMessage(),
                "code" => 500,
            ];
            return $this->generateResponse($response);
        }
        
    }

    public function retryFailedJobs(Request $request) {
        try {

            $job_id = $request->input('id');
            $exitCode = null;

            if($job_id != null) {
                $exitCode = Artisan::call('queue:retry', [
                    $job_id, '--queue' => 'default'
                ]);
            } else {
                $exitCode = Artisan::call('queue:retry all');
            }
                
            $response = (object) [
                "message" => 'jobs requeued successfully',
                "status" => true,
                "data" => $exitCode
            ];
            
            return $this->generateResponse($response);

        } catch(Exception $err) {
            $response = (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $err->getMessage(),
                "code" => 500,
            ];
            return $this->generateResponse($response);
        }
        
    }

    public function startJobsQueue() {
        try {
            $exitCode = Artisan::call('queue:retry all');

            $response = (object) [
                "message" => 'jobs started successfully',
                "status" => true,
                "data" => $exitCode
            ];
            
            return $this->generateResponse($response);

        } catch(Exception $err) {
            $response = (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $err->getMessage(),
                "code" => 500,
            ];
            return $this->generateResponse($response);
        }
    }
}
