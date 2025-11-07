<?php
  
namespace App\Enums;

use ValueError;

enum EventStatus:string {
    case PENDING = 'pending';
    case ONGOING = 'ongoing';
    case COMPLETE = 'complete';
    case PRESALE = 'presale';
    case SOLDOUT = 'soldout';
    case CANCELLED = 'cancelled';
    case CLOSED = 'closed';
    case POSTPONED = 'postponed';

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