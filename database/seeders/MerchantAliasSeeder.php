<?php

namespace Database\Seeders;

use App\Models\MerchantAlias;
use Illuminate\Database\Seeder;

class MerchantAliasSeeder extends Seeder
{
    public function run(): void
    {
        $aliases = [
            'AMZN MKTP' => 'Amazon',
            'AMAZON.COM' => 'Amazon',
            'AMZN.COM' => 'Amazon',
            'AMAZON PRIME' => 'Amazon',
            'AMZN DIGITAL' => 'Amazon',
            'AMZN' => 'Amazon',
            'WMT GROCERY' => 'Walmart',
            'WAL-MART' => 'Walmart',
            'WALMART.COM' => 'Walmart',
            'WM SUPERCENTER' => 'Walmart',
            'TARGET' => 'Target',
            'TARG' => 'Target',
            'COSTCO WHSE' => 'Costco',
            'COSTCO.COM' => 'Costco',
            'APPLE.COM/BILL' => 'Apple',
            'APL*APPLE' => 'Apple',
            'GOOGLE *' => 'Google',
            'PAYPAL *' => 'PayPal',
            'SQ *' => 'Square',
            'TST*' => 'Toast',
            'SHOPIFY*' => 'Shopify',
            'BESTBUYCOM' => 'Best Buy',
            'BEST BUY' => 'Best Buy',
            'BBY' => 'Best Buy',
            'HOMEDEPOT.COM' => 'Home Depot',
            'THE HOME DEPOT' => 'Home Depot',
            'LOWES' => "Lowe's",
            'EBAY' => 'eBay',
            'CHEWY.COM' => 'Chewy',
            'DOORDASH' => 'DoorDash',
            'DD DOORDASH' => 'DoorDash',
            'UBER EATS' => 'Uber Eats',
            'UBER *EATS' => 'Uber Eats',
            'GRUBHUB' => 'Grubhub',
            'INSTACART' => 'Instacart',
            'NETFLIX.COM' => 'Netflix',
            'NETFLIX' => 'Netflix',
            'HULU' => 'Hulu',
            'SPOTIFY' => 'Spotify',
            'DISNEY PLUS' => 'Disney+',
            'DISNEYPLUS' => 'Disney+',
            'PCI RACE' => 'PCI Race Radios',
            'PCIRACERADIO' => 'PCI Race Radios',
            'KARTEK' => 'Kartek Off-Road',
            'KARTEKOFFROAD' => 'Kartek Off-Road',
        ];

        foreach ($aliases as $bankName => $normalizedName) {
            MerchantAlias::updateOrCreate(
                ['bank_name' => $bankName, 'normalized_name' => $normalizedName],
                ['source' => 'hardcoded']
            );
        }
    }
}
