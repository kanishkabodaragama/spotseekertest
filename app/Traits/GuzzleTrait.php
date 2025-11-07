<?php

namespace App\Traits;

use Exception;
use GuzzleHttp;
use Illuminate\Support\Facades\Log;

trait GuzzleTrait
{

    /**
     * @param $url
     * @return GuzzleHttp\Message\FutureResponse|GuzzleHttp\Message\ResponseInterface|GuzzleHttp\Ring\Future\FutureInterface|null
     * Setup function for the guzzle requests going out to the live subscription site
     */
    private function guzzleRequest($url)
    {
        $client = new GuzzleHttp\Client();
        try {
            $subResponse = $client->get($url);

            return $subResponse;
        } catch (Exception $err) {
            Log::error("Post request error: " . $err);
        }
    }

    private function guzzlePostRequest($data, $url, $auth)
    {
        $client = new GuzzleHttp\Client();
        $client->setDefaultOption('headers', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'authorization' => 'Bearer ' . $auth,
            'cache-control' => 'no-cache',
            'accept' => 'application/json'
        ]);

        try {
            $subResponse = $client->post($url, [
                'form_params' => $data
            ]);

            return $subResponse;
        } catch (Exception $err) {
            Log::error("Post request error: " . $err);
        }
    }
}
