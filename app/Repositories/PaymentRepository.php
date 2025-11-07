<?php

namespace App\Repositories;

use App\Models\PaymentGateway;
use App\Services\PaymentService;
use App\Traits\WebXPayApi;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PaymentRepository implements PaymentService {

    use WebXPayApi;

    public function proceedToPayment(Request $request)
    {
        return $this->paymentProceed($request);
        
    }

    public function getPaymentGateways(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        // Get paginated results
        $pgs = PaymentGateway::paginate($perPage, ['*'], 'page', $page);

        $eventsSerialized = $pgs->getCollection()->map->serialize();

        $serializedEventsPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $eventsSerialized,
            $pgs->total(),
            $pgs->perPage(),
            $pgs->currentPage(),
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return (object) [
            "status" => true,
            "code" => 200,
            "message" => 'payment gateways retrieved successfully',
            "data" => $serializedEventsPaginator
        ];
    }

    public function getPaymentGateway($id) {
        $pg = PaymentGateway::findOrFail($id);

        return (object) [
            "message" => 'payment gateways retrieved successfully',
            "status" => true,
            "data" => $pg
        ];
    }

    public function addPaymentGateway(Request $request)
    {

        DB::beginTransaction();

        try {

            $logo_img_url = null;

            if ($request->file('logo')) {
                $logo_img_file = $request->file('logo');
                $logo_img_filename = date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-pg." . $request->file('logo')->extension();

                try {
                    $path = Storage::disk('s3')->putFileAs(env('AWS_BUCKET_PATH') . "/events", $logo_img_file, $logo_img_filename);
                    $logo_img_url = Storage::disk('s3')->url($path);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            $newPaymentGateway = PaymentGateway::create([
                'name' => $request->input('name'),
                'logo' => $logo_img_url,
                'commission_rate' => $request->input('commission_rate'),
                'apply_handling_fee' => $request->input('apply_handling_fee')
            ]);

            DB::commit();

            return (object) [
                "message" => 'payment gateway created successfully',
                "status" => true,
                "data" => $newPaymentGateway
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function updatePaymentGateway(Request $request, $id)
    {

        DB::beginTransaction();

        try {
            $logo_img_url = null;

            if ($request->file('logo')) {
                $logo_img_file = $request->file('logo');
                $logo_img_filename = date('YmdHi') . "-" . str_replace(' ', '-', strtolower($request->input('name'))) . "-pg." . $request->file('logo')->extension();

                try {
                    $path = Storage::disk('s3')->putFileAs(env('AWS_BUCKET_PATH') . "/events", $logo_img_file, $logo_img_filename);
                    $logo_img_url = Storage::disk('s3')->url($path);
                } catch (Exception $err) {
                    Log::debug('s3 file upload error | ' . $err);
                }
            }

            $paymentGateway = PaymentGateway::findOrFail($id);

            $paymentGateway->name = $request->input('name');
            $paymentGateway->commission_rate = $request->input('commission_rate');
            $paymentGateway->active = (bool)$request->input('active');
            $paymentGateway->apply_handling_fee = (bool)$request->input('apply_handling_fee');

            if ($logo_img_url) {
                $paymentGateway->logo = $logo_img_url;
            }

            $paymentGateway->save();

            DB::commit();

            return (object) [
                "message" => 'payment gateway updated successfully',
                "status" => true,
                "data" => $paymentGateway
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function deletePaymentGateway($id)
    {
        DB::beginTransaction();

        try {
            // Find payment gateway and ensure it doesn't have related events
            $pg = PaymentGateway::doesntHave('events')->findOrFail($id);

            $pg->delete();

            DB::commit();

            return (object) [
                "message" => 'Payment gateway deleted successfully',
                "status" => true
            ];
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return (object) [
                "message" => 'Payment gateway cannot be deleted (either not found or has associated events)',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 404
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => 'Something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }
}