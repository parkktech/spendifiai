<?php

namespace App\Enums;

enum UserType: string
{
    case Personal = 'personal';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::Accountant => 'Accountant',
        };
    }

    public function isAccountant(): bool
    {
        return $this === self::Accountant;
    }
}
