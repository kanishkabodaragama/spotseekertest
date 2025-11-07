<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentGateway;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {

        $gateways = [
                [
                    'id' => '70470dcb-752a-4278-a4bb-26df4f4a41e9',
                    'name' => 'VISA / Master',
                    'logo' => 'visa/master',
                    'commission_rate' => 0.00,
                    'apply_handling_fee' => 0,
                    'active' => 1
                ],
                [
                    'id' => '9e96e295-f26a-46e5-896d-831d6f112b06',
                    'name' => 'KOKO (Buy Now Pay Later)',
                    'logo' => 'koko',
                    'commission_rate' => 0.00,
                    'apply_handling_fee' => 0,
                    'active' => 1
                ]
            ];

        foreach ($gateways as $gateway) {
            PaymentGateway::updateOrCreate(
                ['id' => $gateway['id']],
                $gateway
            );
        }
    }
}
