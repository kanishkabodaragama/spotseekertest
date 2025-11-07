<?php

namespace App\Traits;

use Exception;
use GuzzleHttp\Client;

trait WebXPayApi
{

    use ApiResponse;
    
    private $client;

    function __construct()
    {
        $this->client = new Client(
            [
                'base_uri' => env('WEBXPAY_BASE_URL'),
                'timeout'  => 100.0,
                'verify' => false
            ]
        );
    }

    function paymentProceed($orderData)
    {

        try{
            $data = $orderData;

            $plaintext = '525|10';
            $publickey = env('WEBXPAY_PUBLIC_KEY');
    
            //load public key for encrypting
            openssl_public_encrypt($plaintext, $encrypt, $publickey);
    
            //encode for data passing
            $payment = base64_encode($encrypt);
    
            $data['payment'] = $payment;
            $data['secret_key'] = env('WEBXPAY_SECRET_KEY');;
    
                $response = $this->client->request(
                    'POST',
                    "index.php?route=checkout/billing",
                    [
                        'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json'],
                        'body' => json_encode($data)
                    ]
                );
    
            //$response = json_decode((string) $response->getBody());
    
            return $this->generateResponse('payment successfully', $response);
        }
        catch(Exception $e) {
            return $this->generateResponse($e->getMessage(), '', 'Failed', 500);
        }

        
    }
}
