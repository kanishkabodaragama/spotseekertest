<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    use ApiResponse;

    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function getPaymentGateways(Request $request) {
        $response = $this->paymentService->getPaymentGateways($request);

        return $this->generateResponse($response);
    }

    public function getPaymentGateway($id) {
        $response = $this->paymentService->getPaymentGateway($id);

        return $this->generateResponse($response);
    }

    public function addPaymentGateway(Request $request) {

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'logo' => 'required|file',
            'commission_rate' => 'required|string',
            'apply_handling_fee' => 'required'
        ]);

        if ($validator->fails()) {
            return (object) [
                "message" => 'validation failed',
                "status" => false,
                "errors" => $validator->messages()->toArray(),
                "code" => 422,
                "data" => []
            ];
        }

        $response = $this->paymentService->addPaymentGateway($request);

        return $this->generateResponse($response);
    }

    public function updatePaymentGateway(Request $request, $id) {

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'logo' => 'file',
            'commission_rate' => 'required',
            'apply_handling_fee' => 'required',
            'active' => 'required'
        ]);

        if ($validator->fails()) {
            return (object) [
                "message" => 'validation failed',
                "status" => false,
                "errors" => $validator->messages()->toArray(),
                "code" => 422,
                "data" => []
            ];
        }

        $response = $this->paymentService->updatePaymentGateway($request, $id);

        return $this->generateResponse($response);
    }

    public function removePaymentGateway($id) {

        $response = $this->paymentService->deletePaymentGateway($id);

        return $this->generateResponse($response);
    }
}
