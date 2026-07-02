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
            || in_array($queueKey, self::FINDING_KEY_TEMPLATES, true);
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
