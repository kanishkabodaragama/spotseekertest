<?php

namespace App\Services;

use Illuminate\Http\Request;

interface PromotionService {
    public function checkPromoCodeValidity(Request $request);
}