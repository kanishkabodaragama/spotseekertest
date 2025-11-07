<?php

namespace App\Services;

use Illuminate\Http\Request;

interface PaymentService {
    public function proceedToPayment(Request $request);
    public function getPaymentGateways(Request $request);
    public function getPaymentGateway($id);
    public function addPaymentGateway(Request $request);
    public function updatePaymentGateway(Request $request, $id);
    public function deletePaymentGateway($id);
}