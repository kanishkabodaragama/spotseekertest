<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;

class AppleToken
{
    protected Configuration $jwtConfig;

    public function __construct(Configuration $jwtConfig)
    {
        $this->jwtConfig = $jwtConfig;
    }

    /**
     * Generates the client_secret for Sign-in with Apple on iOS (iOS platform)
     * or on the web (Android platform) based on the value of $useBundleId.
     *
     * @see https://bannister.me/blog/generating-a-client-secret-for-sign-in-with-apple-on-each-request
     *
     * @return string
     */
    public function generateClientSecret(): string
    {
            $now = CarbonImmutable::now();

            $token = $this->jwtConfig->builder()
                ->issuedBy(config('services.apple.team_id'))
                ->issuedAt($now)
                ->expiresAt($now->addHour())
                ->permittedFor('https://appleid.apple.com')
                ->relatedTo(config('services.apple.client_id'))
                ->withHeader('kid', config('services.apple.key_id'))
                ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

            return $token->toString();
    }
}
