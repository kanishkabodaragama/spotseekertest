<?php
  
namespace App\Enums;

use ValueError;

enum PaymentStatus:string {
    case PENDING = 'pending';
    case COMPLETE = 'complete';
    case FAILED = 'failed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case PARTIALLY_VERIFIED = 'partially verified';
    case VERIFIED = 'verified';

    public static function fromName(string $name): string
    {
        foreach (self::cases() as $status) {
            if( $name === $status->value ){
                return $status->value;
            }
        }
        throw new ValueError("$name is not a valid backing value for enum " . self::class );
    }
}

