<?php

namespace App\Repositories;

use App\Models\Promotion;
use App\Models\TicketPackage;
use App\Models\TicketSale;
use App\Models\User;
use App\Services\PromotionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PromotionRepository implements PromotionService
{

    public function checkPromoCodeValidity(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'promo_code' => 'required|string',
                'ticket_package_details' => 'required|array|max:1',
                'email' => 'required|email',
                'event_id' => 'required|integer'
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

            $promo_code = $request->input('promo_code');
            $ticket_package_details = $request->input('ticket_package_details');
            $user_email = $request->input('email');

            $promotion = Promotion::with('ticket_package')->where([
                ['coupon_code', $promo_code],
                ['event_id', $request->input('event_id')]
            ])->first();

            if ($promotion == null) {
                return (object) [
                    "message" => 'Invalid Promo Code',
                    "status" => false,
                    "errors" => ["promoCode" => 'Invalid Promo Code'],
                    "code" => 400,
                ];
            }


            $promo_bookings = TicketSale::with('user', 'packages')
                ->whereHas('user', function ($query) use ($user_email) {
                    return $query->where('email', $user_email);
                })
                ->whereHas('packages', function ($query) use ($promotion) {
                    return $query->where('promo_id', '=', $promotion->id);
                })
                ->where('payment_status', '=', 'complete')
                ->get();

            log::info($promo_bookings);

            $redeem_count =  null;

            if ($promotion->redeems === "unlimited") {
                $redeem_count = INF; // Represents positive infinity
            } else {
                $redeem_count = (int)$promotion->redeems;
            }

            if (sizeof($promo_bookings) > $redeem_count) {
                return (object) [
                    "message" => 'Promo code has reached its maximum redemption limit',
                    "status" => false,
                    "errors" => ["promoCode" => 'Promo code has reached its maximum redemption limit'],
                    "code" => 400,
                ];
            }


            foreach ($ticket_package_details as $ticket_pack) {
                $validator = Validator::make($ticket_pack, [
                    'package_id' => 'required',
                    'ticket_count' => 'required',
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

                $selectedTicketPackage = TicketPackage::findOrFail($ticket_pack['package_id']);

                if ($promotion->package_id == null && $promotion->event_id != null) {
                    return $this->checkEligibility($ticket_pack, $promotion, $selectedTicketPackage);
                } else if ($selectedTicketPackage->id == $promotion->package_id && $ticket_pack['ticket_count'] > 0) {
                    return $this->checkEligibility($ticket_pack, $promotion, $selectedTicketPackage);
                }
            }



            return (object) [
                "message" => 'Invalid Promo Code',
                "status" => false,
                "errors" => ["promoCode" => 'Invalid Promo Code'],
                "code" => 400,
            ];
        } catch (Exception $e) {
            Log::error("Promotion validation | " . $e);
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => $e->getCode(),
            ];
        }
    }

    private function checkEligibility($ticket_pack, $promotion, $selectedTicketPackage)
    {
        if ($ticket_pack['ticket_count'] >= $promotion->min_tickets && $ticket_pack['ticket_count'] <= $promotion->max_tickets) {

            $total_amount = $ticket_pack['ticket_count'] * $selectedTicketPackage->price;
            $total_discount = $total_amount;

            if($promotion->per_ticket) {
                if ($promotion->percentage) {
                    $package_discount = $selectedTicketPackage->price * ($promotion->discount_amount / 100);
                    $total_discount = $package_discount * $ticket_pack['ticket_count'];
                } else {
                    $total_discount = $promotion->discount_amount * $ticket_pack['ticket_count'];
                }
            } else {
                if ($promotion->percentage) {
                    $total_discount = $total_amount * ($promotion->discount_amount / 100);
                } else {
                    $total_discount = $promotion->discount_amount;
                }
            }
            

            $total_due = $total_amount - $total_discount;

            $promo = (object) [
                "package_id" => $selectedTicketPackage->id,
                "promo_code" => $promotion->coupon_code,
                "promo_id" => $promotion->id,
                "total_amt" => $total_amount,
                "disc_amt" => $total_discount,
                "total_due" => $total_due
            ];

            return (object) [
                "message" => 'promo code valid',
                "status" => true,
                "data" => $promo
            ];
        } else if ($ticket_pack['ticket_count'] < $promotion->min_tickets) {
            return (object) [
                "message" => 'Invalid Promo Code',
                "status" => false,
                "errors" => ["promoCode" => "To take advantage of the entered promo code, please ensure a purchase of a minimum of ". $promotion->min_tickets ." tickets. This will make you eligible for the promotion."],
                "code" => 400,
            ];
        } else {
            return (object) [
                "message" => 'Invalid Promo Code',
                "status" => false,
                "errors" => ["promoCode" => "The promo code benefits apply when purchasing up to ". $promotion->max_tickets ." tickets or fewer. Please adjust the ticket count accordingly to enjoy the offer."],
                "code" => 400,
            ];
        }
    }
}
