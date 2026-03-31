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
    case B_1099 = '1099_b';
    case R_1099 = '1099_r';
    case G_1099 = '1099_g';
    case K_1099 = '1099_k';
    case S_1099 = '1099_s';
    case SA_1099 = '1099_sa';
    case C_1099 = '1099_c';
    case E_1098 = '1098_e';
    case T_1098 = '1098_t';
    case W2G = 'w2g';
    case K1 = 'k1';
    case SSA_1099 = 'ssa_1099';
    case RRB_1099 = 'rrb_1099';
    case HSA_5498 = '5498_sa';
    case IRA_5498 = '5498';
    case PropertyTax = 'property_tax';
    case CharitableDonation = 'charitable_donation';

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
            self::B_1099 => '1099-B Capital Gains',
            self::R_1099 => '1099-R Retirement Distributions',
            self::G_1099 => '1099-G Government Payments',
            self::K_1099 => '1099-K Payment Card',
            self::S_1099 => '1099-S Real Estate Proceeds',
            self::SA_1099 => '1099-SA HSA Distributions',
            self::C_1099 => '1099-C Cancellation of Debt',
            self::E_1098 => '1098-E Student Loan Interest',
            self::T_1098 => '1098-T Tuition',
            self::W2G => 'W-2G Gambling Winnings',
            self::K1 => 'Schedule K-1',
            self::SSA_1099 => 'SSA-1099 Social Security',
            self::RRB_1099 => 'RRB-1099 Railroad Retirement',
            self::HSA_5498 => '5498-SA HSA Contributions',
            self::IRA_5498 => '5498 IRA Contributions',
            self::PropertyTax => 'Property Tax Statement',
            self::CharitableDonation => 'Charitable Donation Receipt',
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
