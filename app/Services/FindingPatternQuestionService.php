<?php

namespace App\Services;

use App\Models\OptimizationFinding;
use App\Models\Subscription;
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

    public function isPatternType(string $findingType): bool
    {
        return in_array($findingType, self::PATTERN_TYPES, true);
    }

    public function isPatternKey(string $factKey): bool
    {
        return str_starts_with($factKey, self::PATTERN_PREFIX)
            && $this->isPatternType(substr($factKey, strlen(self::PATTERN_PREFIX)));
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
        if (! $this->isPatternKey($factKey)) {
            return null;
        }

        return match (substr($factKey, strlen(self::PATTERN_PREFIX))) {
            'deductible_saas' => $this->deductibleSaasTemplate($user, $taxYear),
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
