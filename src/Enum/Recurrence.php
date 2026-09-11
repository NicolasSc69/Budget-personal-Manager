<?php

namespace App\Enum;

enum Recurrence: string
{
    case NONE = 'none';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case SEMIANNUAL = 'semiannual';
    case YEARLY = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'None',
            self::MONTHLY => 'Every month',
            self::QUARTERLY => 'Every 3 months',
            self::SEMIANNUAL => 'Every 6 months',
            self::YEARLY => 'Every year',
        };
    }
}
