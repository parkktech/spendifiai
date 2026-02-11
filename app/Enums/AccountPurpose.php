<?php

namespace App\Enums;

enum AccountPurpose: string
{
    case Personal   = 'personal';
    case Business   = 'business';
    case Mixed      = 'mixed';
    case Investment = 'investment';

    public function defaultExpenseType(): ExpenseType
    {
        return match ($this) {
            self::Business => ExpenseType::Business,
            self::Mixed    => ExpenseType::Mixed,
            default        => ExpenseType::Personal,
        };
    }

    public function defaultTaxDeductible(): bool
    {
        return $this === self::Business;
    }

    public function label(): string
    {
        return match ($this) {
            self::Personal   => 'Personal',
            self::Business   => 'Business',
            self::Mixed      => 'Mixed Use',
            self::Investment => 'Investment',
        };
    }
}
