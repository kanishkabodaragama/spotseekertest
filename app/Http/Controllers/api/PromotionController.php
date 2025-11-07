<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\PromotionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    use ApiResponse;

    private PromotionService $promotionRepository;

    public function __construct(PromotionService $promotionRepository)
    {
        $this->promotionRepository = $promotionRepository;
    }

    public function checkPromo(Request $request) {
        $response = $this->promotionRepository->checkPromoCodeValidity($request);
        return $this->generateResponse($response);
    }
}
