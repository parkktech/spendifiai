<?php

namespace Database\Seeders;

use App\Models\DetectionMerchant;
use Illuminate\Database\Seeder;

/**
 * DetectionMerchantSeeder — FLAG-10 merchant knowledge table seed.
 *
 * Merchant lists sourced from:
 *  - transaction-detection-distilled.md §§1–8 (vehicle, solar, pool/spa, landscaping,
 *    home improvement, animals/security, medical, travel)
 *  - transaction-detection-v2-delta-distilled.md §13 (gambling signals)
 *
 * Pattern: follows CancellationProviderSeeder (aliases JSON array, firstOrCreate for
 * idempotency, no migrate:fresh/rollback in production).
 *
 * IDEMPOTENT: uses firstOrCreate on company_name — safe to re-run without duplication.
 *
 * PRODUCTION DEPLOYMENT:
 *   php artisan db:seed --class=DetectionMerchantSeeder
 * NEVER run:
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed   (bare — runs ALL seeders)
 */
class DetectionMerchantSeeder extends Seeder
{
    public function run(): void
    {
        $merchants = [

            // ── Vehicle / Powersports / Race Parts (TD-v1 §1) ────────────────
            // MCC 5533 / 5571 / 5599; business-vehicle parts / powersports
            // Auto-loan interest sub-detector: recurring payments to captive lenders (§163(h))
            [
                'company_name' => 'AutoZone',
                'aliases' => ['AUTOZONE', 'AUTO ZONE', 'AUTOZONE.COM', 'AUTOZONE #'],
                'category' => 'vehicle',
                'subdetector_key' => 'vehicle_parts',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Auto parts — highest-recall vehicle signal; §179/actual-expense probe',
                'rule_id' => 'category_vehicle',
            ],
            [
                'company_name' => "O'Reilly Auto Parts",
                'aliases' => ["O'REILLY", 'OREILLY', 'OREILLY AUTO', 'O REILLY AUTO'],
                'category' => 'vehicle',
                'subdetector_key' => 'vehicle_parts',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Auto parts',
                'rule_id' => 'category_vehicle',
            ],
            [
                'company_name' => 'Summit Racing',
                'aliases' => ['SUMMIT RACING', 'SUMMIT RACING EQUIPMENT', 'SUMMITRACING'],
                'category' => 'vehicle',
                'subdetector_key' => 'vehicle_parts',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Performance / race parts — sponsorship module if branded/raced',
                'rule_id' => 'category_vehicle',
            ],
            [
                'company_name' => 'Rocky Mountain ATV/MC',
                'aliases' => ['ROCKY MOUNTAIN ATV', 'ROCKY MOUNTAIN ATV MC', 'RMATV', 'ROCKY MOUNTAIN ATVM'],
                'category' => 'vehicle',
                'subdetector_key' => 'offroad_vehicle',
                'defensibility_rating' => 'conditional',
                'gray_area' => true,
                'notes' => 'Off-road / powersports parts; fuel-tax-credit module needs gallons log',
                'rule_id' => 'category_vehicle',
            ],
            [
                'company_name' => 'RevZilla',
                'aliases' => ['REVZILLA', 'REVZILLA.COM'],
                'category' => 'vehicle',
                'subdetector_key' => 'vehicle_parts',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Motorcycle / powersports gear',
                'rule_id' => 'category_vehicle',
            ],
            [
                'company_name' => 'Jegs',
                'aliases' => ['JEGS', 'JEGS.COM', 'JEGS HIGH PERFORMANCE'],
                'category' => 'vehicle',
                'subdetector_key' => 'vehicle_parts',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Performance / race parts',
                'rule_id' => 'category_vehicle',
            ],

            // ── Solar / Battery / Energy (TD-v1 §2) ──────────────────────────
            // Loan servicers = HIGHEST-RECALL solar signal; credit §25D expired 2025-12-31
            [
                'company_name' => 'GoodLeap',
                'aliases' => ['GOODLEAP', 'GOOD LEAP', 'GOODLEAP LLC', 'GOODLEAP LOAN'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_loan_servicer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar loan servicer — highest-recall retro-scanner signal for §25D',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'Mosaic Solar',
                'aliases' => ['MOSAIC', 'MOSAIC SOLAR', 'JOINMOSAIC', 'MOSAIC LOAN'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_loan_servicer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar loan servicer — §25D retro scanner signal',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'Dividend Finance',
                'aliases' => ['DIVIDEND', 'DIVIDEND FINANCE', 'DIVIDEND SOLAR'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_loan_servicer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar loan servicer',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'Sunlight Financial',
                'aliases' => ['SUNLIGHT FINANCIAL', 'SUNLIGHT FIN', 'SUNLIGHTFINANCIAL'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_loan_servicer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar loan servicer',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'EnFin',
                'aliases' => ['ENFIN', 'ENFIN SOLAR', 'EN-FIN'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_loan_servicer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar loan servicer',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'Tesla Energy',
                'aliases' => ['TESLA ENERGY', 'TESLA SOLAR', 'TESLAENERGY'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_installer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar installer — WHEN-first question (pre-2026 vs 2026+)',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'Sunrun',
                'aliases' => ['SUNRUN', 'SUNRUN INC', 'SUNRUN SOLAR'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_installer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar installer',
                'rule_id' => 'category_solar',
            ],
            [
                'company_name' => 'SunPower',
                'aliases' => ['SUNPOWER', 'SUNPOWER CORP', 'SUNPOWER SOLAR'],
                'category' => 'solar_energy',
                'subdetector_key' => 'solar_installer',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Solar installer',
                'rule_id' => 'category_solar',
            ],

            // ── Pool / Spa (TD-v1 §3) ─────────────────────────────────────────
            // Medical module (gray_area=true): physician-prescribed condition → medical deduction
            [
                'company_name' => 'Presidential Pools',
                'aliases' => ['PRESIDENTIAL POOLS', 'PRESIDENTIAL', 'PRESIDENTIAL POOLS & SPAS'],
                'category' => 'pool_spa',
                'subdetector_key' => 'pool_builder',
                'defensibility_rating' => 'conditional',
                'gray_area' => true,
                'notes' => 'Pool contractor — ask property type; medical module if prescribed (pro tier)',
                'rule_id' => 'category_pool_spa',
            ],
            [
                'company_name' => 'Shasta Pools',
                'aliases' => ['SHASTA', 'SHASTA POOLS', 'SHASTA POOLS & SPAS'],
                'category' => 'pool_spa',
                'subdetector_key' => 'pool_builder',
                'defensibility_rating' => 'conditional',
                'gray_area' => true,
                'notes' => 'Pool contractor',
                'rule_id' => 'category_pool_spa',
            ],
            [
                'company_name' => 'California Pools',
                'aliases' => ['CALIFORNIA POOLS', 'CA POOLS'],
                'category' => 'pool_spa',
                'subdetector_key' => 'pool_builder',
                'defensibility_rating' => 'conditional',
                'gray_area' => true,
                'notes' => 'Pool contractor',
                'rule_id' => 'category_pool_spa',
            ],
            [
                'company_name' => "Leslie's Pool Supply",
                'aliases' => ['LESLIES', "LESLIE'S", 'LESLIES POOL', "LESLIE'S POOL SUPPLY", 'LESLIES POOLMART'],
                'category' => 'pool_spa',
                'subdetector_key' => 'pool_supply',
                'defensibility_rating' => 'conditional',
                'gray_area' => true,
                'notes' => 'Pool supply recurring — maintenance chemicals excluded from basis; medical-use % if prescribed',
                'rule_id' => 'category_pool_spa',
            ],

            // ── Landscaping / Hardscape (TD-v1 §4) ───────────────────────────
            // Business clients visiting home office: grounds deduction (Langer line, pro tier)
            [
                'company_name' => 'SiteOne Landscape Supply',
                'aliases' => ['SITEONE', 'SITE ONE', 'SITEONE LANDSCAPE', 'SITEONE SUPPLY'],
                'category' => 'landscaping',
                'subdetector_key' => 'landscape_materials',
                'defensibility_rating' => 'conditional',
                'gray_area' => true,
                'notes' => 'Landscape materials — rental=land improvement 15yr; primary home=basis; client-visits=pro-tier grounds deduction',
                'rule_id' => 'category_landscaping',
            ],

            // ── Home Improvement Stores (TD-v1 §5) — "the ambiguity king" ────
            // Destination question: one-tap home/shop/rental/home-office
            // Grainger/McMaster skew heavily business (R&D credit flag)
            [
                'company_name' => 'Home Depot',
                'aliases' => ['HOME DEPOT', 'THE HOME DEPOT', 'HOMEDEPOT', 'HOME DEPOT #'],
                'category' => 'home_improvement',
                'subdetector_key' => 'home_improvement_general',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'One-tap destination question: home/shop/rental/home-office',
                'rule_id' => 'category_home_improvement',
            ],
            [
                'company_name' => "Lowe's",
                'aliases' => ['LOWES', "LOWE'S", 'LOWES HOME IMPROVEMENT', 'LOWES #'],
                'category' => 'home_improvement',
                'subdetector_key' => 'home_improvement_general',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'One-tap destination question',
                'rule_id' => 'category_home_improvement',
            ],
            [
                'company_name' => 'Ace Hardware',
                'aliases' => ['ACE HARDWARE', 'ACE HDWE', 'ACE #'],
                'category' => 'home_improvement',
                'subdetector_key' => 'home_improvement_general',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'One-tap destination question',
                'rule_id' => 'category_home_improvement',
            ],
            [
                'company_name' => 'Harbor Freight',
                'aliases' => ['HARBOR FREIGHT', 'HARBOR FREIGHT TOOLS', 'HFT #'],
                'category' => 'home_improvement',
                'subdetector_key' => 'home_improvement_general',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Tools — may be shop/business, rental repair, or home',
                'rule_id' => 'category_home_improvement',
            ],
            [
                'company_name' => 'Grainger',
                'aliases' => ['GRAINGER', 'W W GRAINGER', 'WW GRAINGER', 'GRAINGER.COM'],
                'category' => 'home_improvement',
                'subdetector_key' => 'home_improvement_business',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Skews heavily business — R&D credit supply flag if development projects detected',
                'rule_id' => 'category_home_improvement',
            ],
            [
                'company_name' => 'McMaster-Carr',
                'aliases' => ['MCMASTER', 'MCMASTER CARR', 'MCMASTER-CARR'],
                'category' => 'home_improvement',
                'subdetector_key' => 'home_improvement_business',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Prototype/fab supplies — R&D credit supply flag',
                'rule_id' => 'category_home_improvement',
            ],

            // ── Animals & Security (TD-v1 §6) ─────────────────────────────────
            // Guard-dog module (gray_area=true): question + file-assembly + pro routing ONLY
            // Van Dusen foster flow: rescue org letter required for $250+
            [
                'company_name' => 'Chewy',
                'aliases' => ['CHEWY', 'CHEWY.COM', 'CHEWY INC'],
                'category' => 'animals_security',
                'subdetector_key' => 'pet_supply',
                'defensibility_rating' => 'specialist',
                'gray_area' => true,
                'notes' => 'Pet supply recurring — guard-dog / foster module (specialist band; never assert)',
                'rule_id' => 'deduction_pet',
            ],
            [
                'company_name' => 'Petco',
                'aliases' => ['PETCO', 'PETCO ANIMAL SUPPLIES', 'PETCO #'],
                'category' => 'animals_security',
                'subdetector_key' => 'pet_supply',
                'defensibility_rating' => 'specialist',
                'gray_area' => true,
                'notes' => 'Pet supply — guard-dog / foster module (pro tier)',
                'rule_id' => 'deduction_pet',
            ],

            // ── Medical / Health (TD-v1 §7) ───────────────────────────────────
            // HSA-first routing: 100% vs 7.5% AGI floor comparison
            // CareCredit medical financing = deduction-bunching trigger
            [
                'company_name' => 'CareCredit',
                'aliases' => ['CARECREDIT', 'CARE CREDIT', 'SYNCHRONY CARECREDIT', 'GE CARECREDIT'],
                'category' => 'medical',
                'subdetector_key' => 'medical_financing',
                'defensibility_rating' => 'conditional',
                'gray_area' => false,
                'notes' => 'Medical financing — surface HSA reimbursement or deduction-bunching (§213)',
                'rule_id' => 'category_medical',
            ],

            // ── Gambling Signals (TD-v2Δ §13) ────────────────────────────────
            // Registered as SIGNAL ONLY — band=suppress via gambling_losses_fully_deductible rule.
            // From 2026: only 90% of losses deductible (OBBBA §70250) → break-even bettors owe tax.
            // These merchants NEVER surface a "fully deductible" finding.
            [
                'company_name' => 'DraftKings',
                'aliases' => ['DRAFTKINGS', 'DRAFTKINGS.COM', 'DK SPORTSBOOK', 'DRAFTKINGS INC'],
                'category' => 'gambling',
                'subdetector_key' => 'gambling_signal',
                'defensibility_rating' => 'suppress',
                'gray_area' => false,
                'notes' => 'Gambling signal only — band=suppress; losses never surfaced as fully deductible (OBBBA §70250 from 2026)',
                'rule_id' => 'gambling_losses_fully_deductible',
            ],
            [
                'company_name' => 'FanDuel',
                'aliases' => ['FANDUEL', 'FANDUEL.COM', 'FANDUEL SPORTSBOOK'],
                'category' => 'gambling',
                'subdetector_key' => 'gambling_signal',
                'defensibility_rating' => 'suppress',
                'gray_area' => false,
                'notes' => 'Gambling signal only — band=suppress',
                'rule_id' => 'gambling_losses_fully_deductible',
            ],
            [
                'company_name' => 'BetMGM',
                'aliases' => ['BETMGM', 'BETMGM.COM', 'BET MGM'],
                'category' => 'gambling',
                'subdetector_key' => 'gambling_signal',
                'defensibility_rating' => 'suppress',
                'gray_area' => false,
                'notes' => 'Gambling signal only — band=suppress',
                'rule_id' => 'gambling_losses_fully_deductible',
            ],
            [
                'company_name' => 'Caesars Sportsbook',
                'aliases' => ['CAESARS', 'CAESARS SPORTSBOOK', 'CAESARS ENTERTAINMENT'],
                'category' => 'gambling',
                'subdetector_key' => 'gambling_signal',
                'defensibility_rating' => 'suppress',
                'gray_area' => false,
                'notes' => 'Casino / sportsbook signal — band=suppress',
                'rule_id' => 'gambling_losses_fully_deductible',
            ],
        ];

        foreach ($merchants as $data) {
            DetectionMerchant::firstOrCreate(
                ['company_name' => $data['company_name']],
                $data
            );
        }
    }
}
