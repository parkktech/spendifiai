<?php

namespace App\Enums;

enum TaxDocumentCategory: string
{
    case W2 = 'w2';
    case NEC_1099 = '1099_nec';
    case INT_1099 = '1099_int';
    case MISC_1099 = '1099_misc';
    case DIV_1099 = '1099_div';
    case Mortgage_1098 = '1098';
    case Receipts = 'receipts';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::W2 => 'W-2',
            self::NEC_1099 => '1099-NEC',
            self::INT_1099 => '1099-INT',
            self::MISC_1099 => '1099-MISC',
            self::DIV_1099 => '1099-DIV',
            self::Mortgage_1098 => '1098 Mortgage',
            self::Receipts => 'Receipts',
            self::Other => 'Other',
        };
    }

    /**
     * Return categories used for the vault category grid UI.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function forGrid(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
