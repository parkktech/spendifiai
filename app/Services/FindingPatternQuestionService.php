<?php

namespace App\Services;

use App\Models\OptimizationFinding;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserTaxFact;
use App\Services\Detectors\DeductibleSaasSweep;

/**
 * FindingPatternQuestionService — D18 rule 3 (aggregate patterns, never
 * per-item interrogation).
 *
 * Maps finding TYPES that share a fact-need onto ONE aggregated, data-grounded
 * interview question. The exemplar (owner Decision 18): all deductible_saas_*
 * findings collapse into a single multi-select —
 *
 *   "Which of these software subscriptions do you use primarily for business?
 *    ☐ Microsoft 365 ($9.99/mo) ☐ Adobe Creative Cloud ($54.99/mo) ☐ None of these"
 *
 * One answer fans out to per-item business-use facts (handled by
 * InterviewOrchestratorService::recordAnswer via the template's `fan_out` flag).
 *
 * D18 rule 2: choice labels are ALWAYS humanized (merchant names + formatted
 * amounts) — never raw finding keys. Finding keys appear only as choice VALUES,
 * which are never rendered.
 *
 * D17: templates built here are deterministic — ZERO Claude calls.
 */
class FindingPatternQuestionService
{
    /** Prefix for synthetic pattern queue keys (e.g. 'pattern.deductible_saas'). */
    public const PATTERN_PREFIX = 'pattern.';

    /**
     * finding_type values that aggregate into a single pattern question.
     *
     * @var string[]
     */
    private const PATTERN_TYPES = ['deductible_saas'];

    /**
     * Individual finding KEYS with a dedicated dynamic template (D18 exemplar 2:
     * the 1099-K/P2P-deposits question). These keep their own queue key — no
     * synthetic pattern key — but render via a data-grounded template builder.
     *
     * @var string[]
     */
    private const FINDING_KEY_TEMPLATES = ['penalty_1099k_mismatch', 'category_vehicle_parts'];

    /**
     * D18 exemplar 4 — CONFIRMATION-shaped questions (shape='confirmation'):
     * an evidence lead (detected data, humanized) + Yes / No / escape hatch,
     * with ALL education demoted to the collapsible context. Applies to every
     * life-event battery + change-monitor trigger question; 14-09's monitor
     * prompts inherit this shape.
     *
     * @var array<string, string> finding_key => evidence lead (static one-liners;
     *                            battery keys resolve their lead dynamically)
     */
    private const CONFIRMATION_TRIGGER_LEADS = [
        'life_event_payroll_stop' => 'Your regular payroll deposits appear to have stopped recently. Did your job or income situation change?',
        'life_event_new_mortgage' => 'We spotted what looks like a new monthly mortgage payment. Did you recently buy or refinance a home?',
        'life_event_marketplace_premium' => 'We spotted payments that look like health-insurance marketplace premiums. Do you have coverage through an ACA marketplace plan?',
        'life_event_escrow_inflow' => 'We spotted a large deposit that looks like it came from a title or escrow company. Did you sell a home this year?',
    ];

    /** Battery finding keys (annual life-event check-ins) — confirmation shape. */
    private const CONFIRMATION_BATTERY_KEYS = [
        'battery_marriage_status',
        'battery_birth_adoption',
        'battery_job_change',
        'battery_inheritance',
        'battery_medicare_enrollment',
    ];

    public function isPatternType(string $findingType): bool
    {
        return in_array($findingType, self::PATTERN_TYPES, true);
    }

    public function isPatternKey(string $factKey): bool
    {
        return str_starts_with($factKey, self::PATTERN_PREFIX)
            && $this->isPatternType(substr($factKey, strlen(self::PATTERN_PREFIX)));
    }

    /** Does this queue key resolve through a dynamic template here? */
    public function hasTemplate(string $queueKey): bool
    {
        return $this->isPatternKey($queueKey)
            || in_array($queueKey, self::FINDING_KEY_TEMPLATES, true)
            || in_array($queueKey, self::CONFIRMATION_BATTERY_KEYS, true)
            || isset(self::CONFIRMATION_TRIGGER_LEADS[$queueKey]);
    }

    /** Synthetic queue key for a pattern finding type. */
    public function patternKey(string $findingType): string
    {
        return self::PATTERN_PREFIX.$findingType;
    }

    /**
     * Build the deterministic aggregated template for a pattern key, or null
     * when no unanswered items remain (→ the key never enters/re-enters the queue).
     *
     * Template shape mirrors config question_templates entries so the
     * orchestrator's createTemplateQuestion()/recordAnswer() consume it
     * unchanged, plus:
     *   'dynamic'  => true  — skip the suggested-confirm resolver (no static fact)
     *   'fan_out'  => [...] — recordAnswer writes a per-item fact per choice
     *   'none_value' => the exclusive "none of these" choice value
     *
     * @return array<string, mixed>|null
     */
    public function templateFor(User $user, int $taxYear, string $factKey): ?array
    {
        if ($this->isPatternKey($factKey)) {
            return match (substr($factKey, strlen(self::PATTERN_PREFIX))) {
                'deductible_saas' => $this->deductibleSaasTemplate($user, $taxYear),
                default => null,
            };
        }

        if (in_array($factKey, self::CONFIRMATION_BATTERY_KEYS, true)
            || isset(self::CONFIRMATION_TRIGGER_LEADS[$factKey])) {
            return $this->confirmationTemplate($user, $taxYear, $factKey);
        }

        return match ($factKey) {
            'penalty_1099k_mismatch' => $this->p2pDeposits1099kTemplate($user, $taxYear),
            'category_vehicle_parts' => $this->vehiclePowersportsTemplate($user, $taxYear),
            default => null,
        };
    }

    // ─── deductible_saas (FLAG-07) — the D18 exemplar ─────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function deductibleSaasTemplate(User $user, int $taxYear): ?array
    {
        $findings = OptimizationFinding::forUser($user->id)
            ->where('tax_year', $taxYear)
            ->where('finding_type', 'deductible_saas')
            ->where('status', 'open')
            ->get(['id', 'finding_key', 'treatment']);

        if ($findings->isEmpty()) {
            return null;
        }

        // Humanized labels: resolve merchant + amount from the live Subscription
        // records the sweep derived these findings from (slug round-trip).
        $subsBySlug = [];
        foreach (DeductibleSaasSweep::activeSaasSubscriptions($user->id) as $sub) {
            $subsBySlug[DeductibleSaasSweep::merchantSlug($sub)] = $sub;
        }

        $choices = [];
        foreach ($findings as $finding) {
            // Skip items whose business-use fact is already answered.
            if (UserTaxFact::currentFact($user->id, "finding.{$finding->finding_key}") !== null) {
                continue;
            }

            $slug = substr($finding->finding_key, strlen('deductible_saas_'));
            $sub = $subsBySlug[$slug] ?? null;

            $choices[] = [
                'value' => $finding->finding_key, // never rendered — value only
                'label' => $sub !== null
                    ? $this->subscriptionLabel($sub)
                    : $this->humanizeSlug($slug),
            ];
        }

        if ($choices === []) {
            return null; // everything already answered — no question to ask
        }

        $choices[] = ['value' => 'none', 'label' => 'None of these'];

        return [
            'question' => 'Which of these software subscriptions do you use primarily for business? Select all that apply.',
            'answer_type' => 'multi_select',
            'choices' => $choices,
            'none_value' => 'none',
            'dynamic' => true,
            'fan_out' => [
                'fact_prefix' => 'finding.',
                'selected_value' => 'yes',
                'unselected_value' => 'no',
                'label_prefix' => 'Business use: ',
            ],
            'volatility' => 'stable',
            'label' => 'Software subscriptions used for business',
        ];
    }

    // ─── Confirmation shape (D18 exemplar 4) — life-event battery + triggers ──

    /**
     * shape='confirmation': one evidence sentence (detected data, humanized) +
     * a Yes/No ask; the finding's dense educational treatment demoted to the
     * collapsible context. Answers also_record the canonical life_event.* fact
     * (tax-year scoped) so the detector never re-emits and 14-09 checklist
     * items can key off it. YES on job-change fans the W-4 review follow-ups.
     *
     * @return array<string, mixed>|null
     */
    private function confirmationTemplate(User $user, int $taxYear, string $findingKey): ?array
    {
        $finding = OptimizationFinding::forUser($user->id)
            ->where('tax_year', $taxYear)
            ->where('finding_key', $findingKey)
            ->where('status', 'open')
            ->first();

        if ($finding === null) {
            return null;
        }

        $battery = \App\Services\Scanners\LifeEventTriggerDetector::batteryDefinition($findingKey);

        // Evidence lead: detected data where derivable, clean label otherwise.
        if ($findingKey === 'battery_job_change') {
            $switch = $this->detectEmployerSwitch($user->id);
            $question = $switch !== null
                ? "It looks like you changed jobs — regular deposits from {$switch['from']} stopped "
                    ."and regular deposits from {$switch['to']} began. Is that right?"
                : ($battery['label'] ?? 'Did you start, leave, or change a job this year?');
        } elseif ($battery !== null) {
            $question = $battery['label'];
        } else {
            $question = self::CONFIRMATION_TRIGGER_LEADS[$findingKey] ?? null;
            if ($question === null) {
                return null;
            }
        }

        $template = [
            'shape' => 'confirmation',
            'question' => $question,
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
            // ALL education (the detector's treatment paragraph) → context.
            'context' => trim((string) $finding->treatment) ?: null,
            'dynamic' => true,
            'volatility' => 'annual',
            'label' => $battery['label'] ?? ucfirst(str_replace(['life_event_', '_'], ['', ' '], $findingKey)),
        ];

        // Battery answers also land on the canonical life_event.* fact key,
        // tax-year scoped — the detector's suppression check and the 14-09
        // Action Center items read THIS key.
        if ($battery !== null) {
            $template['also_record'] = [
                'fact_key' => $battery['fact_key'],
                'tax_year_scoped' => true,
                'label' => $battery['label'],
            ];
        }

        // YES on job-change fans the withholding-review probes via the gated
        // tree — one topic per follow-up, never crammed (D18 rules 3/5).
        if ($findingKey === 'battery_job_change') {
            $template['follow_ups'] = [
                'w4.filing_status' => ['yes'],
                'w4.dependents_claimed' => ['yes'],
            ];
        }

        return $template;
    }

    /**
     * Detect an employer switch from payroll-pattern deposit history: one
     * employer whose regular deposits stopped ≥45 days ago and another whose
     * deposits began recently and continue. Returns humanized names or null.
     *
     * @return array{from: string, to: string}|null
     */
    private function detectEmployerSwitch(int $userId): ?array
    {
        $payrollPatterns = ['payroll', 'direct dep', 'dir dep', 'salary', 'wages'];

        $txs = Transaction::where('user_id', $userId)
            ->where('amount', '<', 0) // credits (deposits)
            ->where('transaction_date', '>=', now()->subMonths(9))
            ->where(function ($q) use ($payrollPatterns) {
                foreach ($payrollPatterns as $pattern) {
                    $q->orWhere('merchant_normalized', 'ILIKE', "%{$pattern}%")
                        ->orWhere('merchant_name', 'ILIKE', "%{$pattern}%");
                }
            })
            ->orderBy('transaction_date')
            ->get(['merchant_name', 'merchant_normalized', 'transaction_date']);

        if ($txs->isEmpty()) {
            return null;
        }

        $stopped = null;
        $started = null;
        foreach ($txs->groupBy('merchant_normalized') as $group) {
            $first = $group->first()->transaction_date;
            $last = $group->last()->transaction_date;

            if ($group->count() >= 3 && $last->lt(now()->subDays(45))) {
                $stopped = $group;
            } elseif ($group->count() >= 2
                && $last->gte(now()->subDays(45))
                && $first->gt(now()->subMonths(6))) {
                $started = $group;
            }
        }

        if ($stopped === null || $started === null) {
            return null;
        }

        $from = $this->humanizeEmployerName((string) $stopped->first()->merchant_name);
        $to = $this->humanizeEmployerName((string) $started->first()->merchant_name);

        return ($from !== null && $to !== null && $from !== $to)
            ? ['from' => $from, 'to' => $to]
            : null;
    }

    /**
     * Humanize a payroll memo into an employer display name (D18 rule 2):
     * "ACME CORP PAYROLL 8827162" → "Acme Corp".
     */
    private function humanizeEmployerName(string $memo): ?string
    {
        $stopwords = [
            'payroll', 'direct', 'deposit', 'dep', 'dir', 'des', 'ppd', 'id',
            'ach', 'salary', 'wages', 'pay', 'net', 'co', 'xx',
        ];

        $tokens = preg_split("/[^a-zA-Z0-9'\\-&]+/", $memo) ?: [];
        $names = [];
        foreach ($tokens as $token) {
            $lower = strtolower($token);
            if ($lower === '' || in_array($lower, $stopwords, true)) {
                continue;
            }
            if (preg_match('/\d/', $token) || strlen($token) > 18) {
                continue; // reference codes carry digits
            }
            $names[] = ucfirst($lower);
            if (count($names) >= 4) {
                break;
            }
        }

        return $names === [] ? null : implode(' ', $names);
    }

    // ─── penalty_1099k_mismatch (FLAG-26) — the D18 P2P-deposits exemplar ─────

    /**
     * "You've received about $896 across 4 Zelle/Venmo payments this year,
     *  including from Raelyn Stiles, Amanda Davis, and April Mayes.
     *  What best describes these payments?" + choices; 1099-K education demoted
     * to the collapsible context.
     *
     * @return array<string, mixed>|null
     */
    private function p2pDeposits1099kTemplate(User $user, int $taxYear): ?array
    {
        $finding = OptimizationFinding::forUser($user->id)
            ->where('tax_year', $taxYear)
            ->where('finding_key', 'penalty_1099k_mismatch')
            ->where('status', 'open')
            ->first();

        if ($finding === null) {
            return null;
        }

        $transactions = Transaction::where('user_id', $user->id)
            ->whereIn('id', (array) ($finding->transaction_ids ?? []))
            ->get(['id', 'amount', 'merchant_name']);

        if ($transactions->isEmpty()) {
            return null;
        }

        $count = $transactions->count();
        $totalDisplay = '$'.number_format(round(abs((float) $transactions->sum('amount'))));

        // Humanized payer names — memo reference codes stripped (D18 rule 2).
        $payers = [];
        $platforms = [];
        foreach ($transactions as $tx) {
            $memo = (string) $tx->merchant_name;
            $name = $this->humanizePayerName($memo);
            if ($name !== null && ! in_array($name, $payers, true)) {
                $payers[] = $name;
            }
            foreach ($this->detectPlatforms($memo) as $platform) {
                if (! in_array($platform, $platforms, true)) {
                    $platforms[] = $platform;
                }
            }
        }

        $platformLabel = $platforms === [] ? 'payment app' : implode('/', array_slice($platforms, 0, 3));

        $lead = "You've received about {$totalDisplay} across {$count} {$platformLabel} payments this year";
        if ($payers !== []) {
            $shown = array_slice($payers, 0, 3);
            $extra = count($payers) - count($shown);
            $namesText = count($shown) > 1
                ? implode(', ', array_slice($shown, 0, -1)).', and '.end($shown)
                : $shown[0];
            $lead .= ', including from '.$namesText;
            if ($extra > 0) {
                $lead .= " and {$extra} other".($extra > 1 ? 's' : '');
            }
        }

        $thresholdCents = (int) config('tax-detection.rules.penalty_1099k_mismatch.threshold_cents', 60_000);
        $thresholdDisplay = '$'.number_format($thresholdCents / 100);

        return [
            'question' => $lead.'. What best describes these payments?',
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'mostly_personal', 'label' => 'Mostly personal — friends and family, splitting costs'],
                ['value' => 'mostly_business', 'label' => 'Mostly business income'],
                ['value' => 'mixed', 'label' => 'A mix of personal and business'],
                ['value' => 'not_sure', 'label' => 'Not sure'],
            ],
            'context' => 'Payment apps and marketplaces must send you — and the IRS — Form 1099-K '
                ."when payments to you exceed {$thresholdDisplay} in a year. Personal transfers such as "
                .'gifts, repayments, or splitting costs are not taxable income, while business income is. '
                .'Your answer only helps keep your income picture accurate; nothing is filed or asserted from it.',
            'dynamic' => true,
            'volatility' => 'annual',
            'label' => 'Payment-app deposits classification',
        ];
    }

    // ─── category_vehicle_parts (FLAG-10 vehicle module) — the D18 exemplar 3 ─

    /**
     * "You've made 3 purchases at Rocky Mountain ATV/MC this year (about $925
     *  total). What are these purchases mostly for?" + the detection-spec vehicle
     * question tree as choices. Mileage/fuel-credit/fraud education demoted to
     * context; the usage-log follow-up fans from business-flavored answers as
     * its OWN question (never crammed — D18 rule 3/5).
     *
     * @return array<string, mixed>|null
     */
    private function vehiclePowersportsTemplate(User $user, int $taxYear): ?array
    {
        $finding = OptimizationFinding::forUser($user->id)
            ->where('tax_year', $taxYear)
            ->where('finding_key', 'category_vehicle_parts')
            ->where('status', 'open')
            ->first();

        if ($finding === null) {
            return null;
        }

        $transactions = Transaction::where('user_id', $user->id)
            ->whereIn('id', (array) ($finding->transaction_ids ?? []))
            ->get(['id', 'amount', 'merchant_name', 'merchant_normalized']);

        if ($transactions->isEmpty()) {
            return null;
        }

        $count = $transactions->count();
        $totalDisplay = '$'.number_format(round(abs((float) $transactions->sum('amount'))));
        $merchantLabel = $this->vehicleMerchantLabel($transactions);

        return [
            'question' => "You've made {$count} purchases at {$merchantLabel} this year "
                ."(about {$totalDisplay} total). What are these purchases mostly for?",
            'answer_type' => 'choice',
            'choices' => [
                ['value' => 'personal_hobby', 'label' => 'Personal hobby or recreation'],
                ['value' => 'business_work_vehicle', 'label' => 'A business work vehicle'],
                ['value' => 'sponsorship_advertising', 'label' => 'Race or show vehicles used for sponsorship or advertising'],
                ['value' => 'resale_inventory', 'label' => 'Items I resell'],
                ['value' => 'monetized_content', 'label' => 'Content I monetize (YouTube, sponsorships, etc.)'],
                ['value' => 'offroad_work_equipment', 'label' => 'Off-road work equipment (shop, ranch, or construction)'],
            ],
            'context' => 'If a vehicle is used for business, expenses may be deductible using the '
                .'standard mileage method or the actual expense method — a contemporaneous mileage log '
                .'supports either. Equipment used off-road for business (shop, ranch, construction) may '
                .'qualify for a fuel tax credit, which requires a gallons log; unsupported fuel-credit '
                .'claims are a common IRS red flag. Nothing is claimed from your answer — it only helps '
                .'us route what to track.',
            'dynamic' => true,
            'volatility' => 'stable',
            'label' => 'Vehicle & powersports purchases — primary purpose',
            // D18: follow-ups fan from the choice as their OWN questions.
            'follow_ups' => [
                'vehicle.usage_log_status' => [
                    'business_work_vehicle',
                    'sponsorship_advertising',
                    'resale_inventory',
                    'monetized_content',
                    'offroad_work_equipment',
                ],
            ],
        ];
    }

    /**
     * Resolve the clean merchant display name for the vehicle question: prefer
     * the DetectionMerchant company_name (curated), fall back to a title-cased
     * merchant string.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>|\Illuminate\Database\Eloquent\Collection<int, Transaction>  $transactions
     */
    private function vehicleMerchantLabel($transactions): string
    {
        $normalized = (string) $transactions->groupBy('merchant_normalized')
            ->sortByDesc(fn ($group) => $group->count())
            ->keys()
            ->first();

        $dm = \App\Models\DetectionMerchant::findByNormalizedName($normalized)->first();
        if ($dm !== null) {
            return $dm->company_name;
        }

        return ucwords(strtolower(trim((string) ($transactions->first()->merchant_name ?? $normalized))));
    }

    // ─── P2P memo humanization (D18 rule 2) ───────────────────────────────────

    /**
     * Strip platform words and trailing reference tokens from a P2P bank memo,
     * returning a title-cased payer name — or null when no name survives.
     *
     * "Zelle payment from RAELYN STILES 27868366380" → "Raelyn Stiles"
     * "Zelle payment from AMANDA DAVIS BACpz1r5pufa" → "Amanda Davis"
     * "VENMO PAYMENT 1029384756 APRIL MAYES"         → "April Mayes"
     */
    private function humanizePayerName(string $memo): ?string
    {
        $stopwords = [
            'zelle', 'venmo', 'paypal', 'cash', 'app', 'cashapp', 'square', 'stripe',
            'payment', 'payments', 'from', 'to', 'transfer', 'xfer', 'des', 'ppd',
            'id', 'ref', 'p2p', 'deposit', 'credit', 'inst', 'instant', 'web', 'ach',
        ];

        $tokens = preg_split("/[^a-zA-Z0-9'\\-]+/", $memo) ?: [];
        $names = [];
        foreach ($tokens as $token) {
            $lower = strtolower($token);
            if ($lower === '' || in_array($lower, $stopwords, true)) {
                continue;
            }
            if (preg_match('/\d/', $token)) {
                continue; // reference codes carry digits
            }
            if (strlen($token) > 14 || ! preg_match("/^[a-zA-Z][a-zA-Z'\\-]*$/", $token)) {
                continue;
            }
            $names[] = ucfirst($lower);
            if (count($names) >= 3) {
                break; // first / middle / last is plenty
            }
        }

        return $names === [] ? null : implode(' ', $names);
    }

    /**
     * Platform display names present in a memo string.
     *
     * @return string[]
     */
    private function detectPlatforms(string $memo): array
    {
        $map = [
            'zelle' => 'Zelle',
            'venmo' => 'Venmo',
            'paypal' => 'PayPal',
            'cash app' => 'Cash App',
            'cashapp' => 'Cash App',
            'square' => 'Square',
            'stripe' => 'Stripe',
            'apple pay' => 'Apple Pay',
            'google pay' => 'Google Pay',
        ];

        $lower = strtolower($memo);
        $found = [];
        foreach ($map as $needle => $label) {
            if (str_contains($lower, $needle) && ! in_array($label, $found, true)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** "Microsoft 365 ($9.99/mo)" — merchant name + formatted amount + cadence. */
    private function subscriptionLabel(Subscription $sub): string
    {
        $amount = (float) $sub->amount;
        $display = $amount == floor($amount)
            ? number_format($amount)
            : number_format($amount, 2);

        $cadence = match ($sub->frequency) {
            'weekly' => '/wk',
            'monthly' => '/mo',
            'quarterly' => '/qtr',
            'annual' => '/yr',
            default => '',
        };

        return trim($sub->merchant_name)." (\${$display}{$cadence})";
    }

    /** Last-resort humanization when no live subscription matches the slug. */
    private function humanizeSlug(string $slug): string
    {
        return ucwords(trim(str_replace('_', ' ', $slug)));
    }
}
