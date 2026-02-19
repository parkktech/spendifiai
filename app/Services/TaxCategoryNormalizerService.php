<?php

namespace App\Services;

class TaxCategoryNormalizerService
{
    /**
     * Normalize a raw tax_category string to a canonical IRS line mapping.
     *
     * @return array{line: string, label: string, schedule: string, canonical_name: string}
     */
    public function normalize(string $rawCategory): array
    {
        $lower = strtolower(trim($rawCategory));

        foreach ($this->getKeywordRules() as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (str_contains($lower, strtolower($keyword))) {
                    return [
                        'line' => $rule['line'],
                        'label' => $rule['label'],
                        'schedule' => $rule['schedule'],
                        'canonical_name' => $rule['canonical_name'],
                    ];
                }
            }
        }

        // Default fallback
        return [
            'line' => '27a',
            'label' => 'Other expenses',
            'schedule' => 'C',
            'canonical_name' => 'Other Business Expenses',
        ];
    }

    /**
     * Map an array of deduction categories to IRS lines.
     * Replaces TaxExportService::mapToScheduleC().
     *
     * @param  array  $categories  Each element: ['tax_category' => string, 'total' => float, 'item_count' => int, ...]
     * @return array Grouped by IRS line with totals and contributing categories
     */
    public function mapCategoriesToLines(array $categories): array
    {
        $lines = [];

        foreach ($categories as $cat) {
            $name = $cat['tax_category'];
            $normalized = $this->normalize($name);

            $lineKey = $normalized['schedule'] === 'C'
                ? "Line {$normalized['line']}"
                : $normalized['line'];

            if (! isset($lines[$lineKey])) {
                $lines[$lineKey] = [
                    'line' => $normalized['line'],
                    'label' => $normalized['label'],
                    'schedule' => $normalized['schedule'],
                    'total' => 0,
                    'categories' => [],
                ];
            }

            $lines[$lineKey]['total'] += (float) $cat['total'];
            $lines[$lineKey]['categories'][] = [
                'name' => $name,
                'amount' => (float) $cat['total'],
                'items' => (int) $cat['item_count'],
            ];
        }

        // Sort: Schedule C lines numerically, then Schedule A
        uasort($lines, function ($a, $b) {
            $aOrder = $this->lineSortOrder($a['schedule'], $a['line']);
            $bOrder = $this->lineSortOrder($b['schedule'], $b['line']);

            return $aOrder <=> $bOrder;
        });

        return array_values($lines);
    }

    /**
     * Build a per-category Schedule C map for the API response.
     * Replaces TaxController::scheduleCMap().
     */
    public function buildScheduleCMap(array $categories): array
    {
        $map = [];

        foreach ($categories as $cat) {
            $name = $cat['category'] ?? $cat['tax_category'] ?? '';
            $normalized = $this->normalize($name);
            $map[$name] = [
                'line' => $normalized['line'],
                'label' => $normalized['label'],
                'schedule' => $normalized['schedule'],
            ];
        }

        return $map;
    }

    /**
     * Get all Schedule C lines (8–30) with $0 defaults.
     * Used to render the complete IRS form in the PDF.
     */
    public function getAllScheduleCLines(): array
    {
        return [
            '8' => ['line' => '8', 'label' => 'Advertising', 'total' => 0, 'categories' => []],
            '9' => ['line' => '9', 'label' => 'Car and truck expenses', 'total' => 0, 'categories' => []],
            '10' => ['line' => '10', 'label' => 'Commissions and fees', 'total' => 0, 'categories' => []],
            '11' => ['line' => '11', 'label' => 'Contract labor', 'total' => 0, 'categories' => []],
            '12' => ['line' => '12', 'label' => 'Depletion', 'total' => 0, 'categories' => []],
            '13' => ['line' => '13', 'label' => 'Depreciation and section 179 expense deduction', 'total' => 0, 'categories' => []],
            '14' => ['line' => '14', 'label' => 'Employee benefit programs', 'total' => 0, 'categories' => []],
            '15' => ['line' => '15', 'label' => 'Insurance (other than health)', 'total' => 0, 'categories' => []],
            '16a' => ['line' => '16a', 'label' => 'Mortgage interest paid to financial institutions', 'total' => 0, 'categories' => []],
            '16b' => ['line' => '16b', 'label' => 'Other interest', 'total' => 0, 'categories' => []],
            '17' => ['line' => '17', 'label' => 'Legal and professional services', 'total' => 0, 'categories' => []],
            '18' => ['line' => '18', 'label' => 'Office expense', 'total' => 0, 'categories' => []],
            '19' => ['line' => '19', 'label' => 'Pension and profit-sharing plans', 'total' => 0, 'categories' => []],
            '20a' => ['line' => '20a', 'label' => 'Rent or lease — vehicles, machinery, equipment', 'total' => 0, 'categories' => []],
            '20b' => ['line' => '20b', 'label' => 'Rent or lease — other business property', 'total' => 0, 'categories' => []],
            '21' => ['line' => '21', 'label' => 'Repairs and maintenance', 'total' => 0, 'categories' => []],
            '22' => ['line' => '22', 'label' => 'Supplies (not included in Part III)', 'total' => 0, 'categories' => []],
            '23' => ['line' => '23', 'label' => 'Taxes and licenses', 'total' => 0, 'categories' => []],
            '24a' => ['line' => '24a', 'label' => 'Travel', 'total' => 0, 'categories' => []],
            '24b' => ['line' => '24b', 'label' => 'Deductible meals (50% limitation)', 'total' => 0, 'categories' => []],
            '25' => ['line' => '25', 'label' => 'Utilities', 'total' => 0, 'categories' => []],
            '26' => ['line' => '26', 'label' => 'Wages', 'total' => 0, 'categories' => []],
            '27a' => ['line' => '27a', 'label' => 'Other expenses (see list)', 'total' => 0, 'categories' => []],
            '30' => ['line' => '30', 'label' => 'Business use of home', 'total' => 0, 'categories' => []],
        ];
    }

    /**
     * Get all Schedule A sections with $0 defaults.
     */
    public function getAllScheduleALines(): array
    {
        return [
            'Sch A-medical' => ['line' => 'Sch A-medical', 'label' => 'Medical and dental expenses', 'note' => 'Subject to 7.5% AGI floor', 'total' => 0, 'categories' => []],
            'Sch A-mortgage' => ['line' => 'Sch A-mortgage', 'label' => 'Home mortgage interest', 'note' => null, 'total' => 0, 'categories' => []],
            'Sch A-charity' => ['line' => 'Sch A-charity', 'label' => 'Charitable contributions', 'note' => null, 'total' => 0, 'categories' => []],
            'Sch A-salt' => ['line' => 'Sch A-salt', 'label' => 'State and local taxes (SALT)', 'note' => '$10,000 cap applies', 'total' => 0, 'categories' => []],
        ];
    }

    /**
     * Keyword rules in priority order. First match wins.
     * Order is critical: more specific patterns must come before broader ones.
     */
    protected function getKeywordRules(): array
    {
        return [
            // ── Schedule C Line 8: Advertising ──
            [
                'keywords' => ['advertis', 'marketing', 'promo', 'seo', 'ppc', 'ad spend', 'google ads', 'facebook ads'],
                'line' => '8',
                'label' => 'Advertising',
                'schedule' => 'C',
                'canonical_name' => 'Marketing & Advertising',
            ],

            // ── Schedule C Line 9: Car and truck expenses ──
            // "car " with trailing space avoids matching "healthcare"
            [
                'keywords' => ['car insurance', 'auto maintenance', 'auto repair', 'gas & fuel',
                    'fuel', 'mileage', 'toll', 'parking', 'vehicle', 'transportation',
                    'car ', 'truck', 'oil change', 'tire '],
                'line' => '9',
                'label' => 'Car and truck expenses',
                'schedule' => 'C',
                'canonical_name' => 'Transportation',
            ],

            // ── Schedule C Line 10: Commissions and fees ──
            [
                'keywords' => ['commission', 'platform fee', 'merchant fee', 'processing fee',
                    'stripe fee', 'paypal fee', 'square fee'],
                'line' => '10',
                'label' => 'Commissions and fees',
                'schedule' => 'C',
                'canonical_name' => 'Commissions & Fees',
            ],

            // ── Schedule C Line 11: Contract labor ──
            [
                'keywords' => ['contract labor', 'contractor', 'freelancer payment', 'subcontract',
                    '1099', 'outsourc'],
                'line' => '11',
                'label' => 'Contract labor',
                'schedule' => 'C',
                'canonical_name' => 'Contract Labor',
            ],

            // ── Schedule C Line 13: Depreciation ──
            [
                'keywords' => ['depreci', 'section 179', 'amortiz'],
                'line' => '13',
                'label' => 'Depreciation and section 179',
                'schedule' => 'C',
                'canonical_name' => 'Depreciation',
            ],

            // ── Schedule C Line 15: Insurance (other than health) ──
            [
                'keywords' => ['business insurance', 'liability insurance', 'e&o insurance',
                    'professional insurance', 'workers comp', 'general liability'],
                'line' => '15',
                'label' => 'Insurance (other than health)',
                'schedule' => 'C',
                'canonical_name' => 'Business Insurance',
            ],

            // ── Schedule C Line 16b: Other interest ──
            // Must come before Line 18 "office" to catch "business interest"
            [
                'keywords' => ['business interest', 'business loan', 'business credit card interest',
                    'line of credit interest', 'sba loan', 'interest expense'],
                'line' => '16b',
                'label' => 'Other interest',
                'schedule' => 'C',
                'canonical_name' => 'Business Interest Expense',
            ],

            // ── Schedule C Line 17: Legal and professional services ──
            // Must come before Line 18 "office"
            [
                'keywords' => ['legal', 'attorney', 'lawyer', 'accountant', 'accounting', 'cpa',
                    'bookkeep', 'professional service', 'consulting fee', 'tax prep',
                    'professional fee'],
                'line' => '17',
                'label' => 'Legal and professional services',
                'schedule' => 'C',
                'canonical_name' => 'Professional Services',
            ],

            // ── Schedule C Line 30: Business use of home ──
            // Must come before Line 18 "office" to avoid "home office" matching "office"
            [
                'keywords' => ['home office', 'business use of home', 'work from home'],
                'line' => '30',
                'label' => 'Business use of home',
                'schedule' => 'C',
                'canonical_name' => 'Home Office',
            ],

            // ── Schedule C Line 18: Office expense ──
            [
                'keywords' => ['office', 'software', 'saas', 'digital service', 'technology',
                    'tech tool', 'stationery', 'printer', 'toner', 'ink',
                    'postage', 'shipping', 'stamps'],
                'line' => '18',
                'label' => 'Office expense',
                'schedule' => 'C',
                'canonical_name' => 'Office Expenses',
            ],

            // ── Schedule C Line 20b: Rent or lease ──
            [
                'keywords' => ['business rent', 'office rent', 'coworking', 'co-working',
                    'office space', 'warehouse', 'storage unit', 'studio rent'],
                'line' => '20b',
                'label' => 'Rent or lease — other business property',
                'schedule' => 'C',
                'canonical_name' => 'Business Rent',
            ],

            // ── Schedule C Line 21: Repairs and maintenance ──
            [
                'keywords' => ['repair', 'equipment maintenance'],
                'line' => '21',
                'label' => 'Repairs and maintenance',
                'schedule' => 'C',
                'canonical_name' => 'Repairs & Maintenance',
            ],

            // ── Schedule C Line 22: Supplies ──
            [
                'keywords' => ['supplies', 'materials', 'raw material'],
                'line' => '22',
                'label' => 'Supplies',
                'schedule' => 'C',
                'canonical_name' => 'Supplies',
            ],

            // ── Schedule C Line 23: Taxes and licenses ──
            [
                'keywords' => ['license', 'permit', 'registration', 'business tax',
                    'franchise tax', 'regulatory fee'],
                'line' => '23',
                'label' => 'Taxes and licenses',
                'schedule' => 'C',
                'canonical_name' => 'Taxes & Licenses',
            ],

            // ── Schedule C Line 24a: Travel ──
            [
                'keywords' => ['travel', 'hotel', 'lodging', 'airfare', 'flight',
                    'airline', 'airbnb', 'rental car', 'business trip'],
                'line' => '24a',
                'label' => 'Travel',
                'schedule' => 'C',
                'canonical_name' => 'Travel',
            ],

            // ── Schedule C Line 24b: Deductible meals (50%) ──
            [
                'keywords' => ['meal', 'business dining', 'client dinner',
                    'business lunch', 'working lunch'],
                'line' => '24b',
                'label' => 'Deductible meals (50% limitation)',
                'schedule' => 'C',
                'canonical_name' => 'Business Meals',
            ],

            // ── Schedule C Line 25: Utilities ──
            [
                'keywords' => ['utilit', 'electric', 'water bill', 'power bill',
                    'internet', 'phone', 'broadband', 'telephone', 'cell phone',
                    'mobile phone'],
                'line' => '25',
                'label' => 'Utilities',
                'schedule' => 'C',
                'canonical_name' => 'Utilities',
            ],

            // ── Schedule C Line 26: Wages ──
            [
                'keywords' => ['wage', 'salary paid', 'payroll', 'employee pay', 'staff pay'],
                'line' => '26',
                'label' => 'Wages',
                'schedule' => 'C',
                'canonical_name' => 'Wages',
            ],

            // ── Schedule C Line 27a: Other expenses ──
            // Catch-all for business items that don't fit above
            [
                'keywords' => ['professional develop', 'training', 'conference', 'workshop',
                    'continuing education', 'course', 'certification', 'seminar',
                    'education', 'tuition', 'subscription', 'membership', 'dues',
                    'bank fee', 'bank charge', 'wire transfer fee'],
                'line' => '27a',
                'label' => 'Other expenses',
                'schedule' => 'C',
                'canonical_name' => 'Other Business Expenses',
            ],

            // ═══════════ Schedule A (Personal Deductions) ═══════════

            // ── Medical and dental expenses ──
            [
                'keywords' => ['medical', 'dental', 'health', 'pharmacy', 'prescription',
                    'doctor', 'hospital', 'therapist', 'chiropract', 'optometrist',
                    'vision', 'healthcare'],
                'line' => 'Sch A-medical',
                'label' => 'Medical and dental expenses',
                'schedule' => 'A',
                'canonical_name' => 'Medical & Dental',
            ],

            // ── Mortgage interest (personal) ──
            [
                'keywords' => ['mortgage', 'home loan interest', 'home interest'],
                'line' => 'Sch A-mortgage',
                'label' => 'Home mortgage interest',
                'schedule' => 'A',
                'canonical_name' => 'Mortgage Interest',
            ],

            // ── Charitable contributions ──
            [
                'keywords' => ['charit', 'donation', 'contribut', 'tithe', 'church',
                    'nonprofit', 'non-profit', 'giving', 'philanthrop'],
                'line' => 'Sch A-charity',
                'label' => 'Charitable contributions',
                'schedule' => 'A',
                'canonical_name' => 'Charity & Donations',
            ],

            // ── State and local taxes ──
            [
                'keywords' => ['state tax', 'local tax', 'property tax', 'salt'],
                'line' => 'Sch A-salt',
                'label' => 'State and local taxes (SALT)',
                'schedule' => 'A',
                'canonical_name' => 'State & Local Taxes',
            ],
        ];
    }

    /**
     * Map a Schedule C line to its TXF reference number.
     * TXF v042 spec: taxdataexchange.org/docs/txf/v042/C.txf.html
     */
    public function getTxfRefNumber(string $line): ?string
    {
        $map = [
            '8' => 'N304',     // Advertising
            '9' => 'N306',     // Car and truck expenses
            '10' => 'N307',    // Commissions and fees
            '11' => 'N685',    // Contract labor
            '13' => 'N309',    // Depreciation
            '14' => 'N308',    // Employee benefit programs
            '15' => 'N310',    // Insurance (other than health)
            '16a' => 'N311',   // Mortgage interest paid to financial institutions
            '16b' => 'N312',   // Other interest
            '17' => 'N298',    // Legal and professional services
            '18' => 'N313',    // Office expense
            '19' => 'N314',    // Pension and profit-sharing plans
            '20a' => 'N299',   // Rent — vehicles, machinery, equipment
            '20b' => 'N300',   // Rent — other business property
            '21' => 'N315',    // Repairs and maintenance
            '22' => 'N301',    // Supplies
            '23' => 'N316',    // Taxes and licenses
            '24a' => 'N317',   // Travel
            '24b' => 'N294',   // Deductible meals
            '25' => 'N318',    // Utilities
            '26' => 'N297',    // Wages
            '27a' => 'N302',   // Other expenses (format 3 — amount + description)
            '30' => 'N302',    // Business use of home (falls under other expenses in TXF)
        ];

        return $map[$line] ?? null;
    }

    /**
     * Sort order for IRS lines (Schedule C first, then A).
     */
    protected function lineSortOrder(string $schedule, string $line): int
    {
        if ($schedule === 'C') {
            // Extract numeric part for sorting
            $num = (float) preg_replace('/[^0-9.]/', '', $line);
            // Handle sub-lines: 16a=16.1, 16b=16.2, 20a=20.1, 20b=20.2, 24a=24.1, 24b=24.2, 27a=27.1
            if (str_contains($line, 'a')) {
                $num += 0.1;
            }
            if (str_contains($line, 'b')) {
                $num += 0.2;
            }

            return (int) ($num * 10);
        }

        // Schedule A items sort after C
        return match ($line) {
            'Sch A-medical' => 1000,
            'Sch A-mortgage' => 1001,
            'Sch A-charity' => 1002,
            'Sch A-salt' => 1003,
            default => 1099,
        };
    }
}
