<?php

namespace App\Enums;

enum SubscriptionType: string
{
    case ALL = 'all';
    case EVENT = 'event';

    public static function values(): array
    {
        return array_map(fn($enum) => $enum->value, self::cases());
    }
}
