<?php
  
namespace App\Enums;

use ValueError;

enum Roles:string {
    case ADMIN = 'Admin';
    case MANAGER = 'Manager';
    case USER = 'User';
    case COORDINATOR = 'Coordinator';

    public static function fromName(string $name): string
    {
        foreach (self::cases() as $status) {
            if( $name === $status->value ){
                return $status->value;
            }
        }
        throw new ValueError("$name is not a valid role value for enum " . self::class );
    }
}

