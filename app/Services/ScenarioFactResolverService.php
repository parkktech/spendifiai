<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\IncomeOptimizationProfile;
use App\Models\ScenarioFactSet;
use App\Models\TaxDocument;
use App\Models\User;
use App\Models\UserTaxFact;
use Carbon\Carbon;

/**
 * ScenarioFactResolverService — the read-side fact resolution substrate for
 * optimization scenarios (SCENARIOS-SPEC §A.6, SCN-02).
 *
 * Walks each fact's config-declared source-priority chain
 * (config/optimization-objectives.php) — snapshot / fact (canonical + aliases,
 * year-scoped then unscoped) / profile / deterministic derivation / ask — and
 * returns the FIRST hit as a ResolvedFact array carrying its provenance
 * (source + source_ref citation) and a two-tier confirmed flag (§A.7: known vs
 * confirmed).
 *
 * INVARIANTS (§A.6.4):
 *   - NEVER calls Claude / HTTP. Pure read-side query-time join.
 *   - NEVER writes UserTaxFact rows (no snapshot/profile → facts duplication, §A.7).
 *   - NEVER returns decrypted values through an endpoint (endpoints expose
 *     keys/labels/states only; decrypted values live in-memory or in the
 *     encrypted ScenarioFactSet.resolved_facts column).
 *
 * Mirrors IncomeOptimizerDataAssemblerService conventions: integer-cents-as-string
 * money, normaliseFilingStatus(). Bracket/limit math stays in TaxRulesEngineService;
 * derivations here are plain deterministic arithmetic.
 *
 * ResolvedFact shape (§A.6.2):
 *   [
 *     'fact_key'    => 'employer.federal_withholding',
 *     'value'       => '842000',          // cents-string | enum | yes-no | digit-string
 *     'value_type'  => 'money_cents',
 *     'source'      => 'derived',         // snapshot | fact | profile | derived
 *     'source_ref'  => 'derived:annualize_ytd_federal_tax(doc:512)',
 *     'confirmed'   => false,             // §A.7 two-tier flag
 *     'resolved_at' => '2026-07-02T14:03:11Z',
 *   ]
 */
final class ScenarioFactResolverService
{
    /**
     * Source types that carry user assent → confirmed=true (§A.7).
     * All other current fact source types (profile_field, detector) are `known`
     * (confirmed=false). document_extraction is only ever returned by
     * UserTaxFact::currentFact() once confirmed_at is set, so it graduates here.
     */
    private const CONFIRMED_SOURCE_TYPES = ['interview_answer', 'user_edit', 'document_extraction'];

    /**
     * Resolve every fact in the union of the readiness objective requirement maps
     * (take_home + tax_burden + retirement). Scenario-only domains
     * (is_scenario_domain) are excluded, mirroring ObjectiveReadinessService (§A.8.1).
     *
     * @return array<string, array<string, mixed>|null> fact_key => ResolvedFact|null
     */
    public function resolveAll(User $user, int $taxYear): array
    {
        $out = [];
        foreach ($this->allCanonicalKeys() as $key) {
            $out[$key] = $this->resolve($user, $taxYear, $key);
        }

        return $out;
    }

    /**
     * Resolve one canonical fact through its source chain (incl. aliases + derivations).
     * Returns null when the chain exhausts without a hit (fact needs to be asked).
     *
     * @return array<string, mixed>|null ResolvedFact
     */
    public function resolve(User $user, int $taxYear, string $canonicalKey): ?array
    {
        $spec = $this->factSpec($canonicalKey);

        // Unmapped key: still support a plain confirmed-fact lookup (year then unscoped).
        if ($spec === null) {
            return $this->resolveFromFact($user, $taxYear, $canonicalKey);
        }

        foreach ((array) ($spec['chain'] ?? []) as $step) {
            $resolved = $this->walkStep($user, $taxYear, $canonicalKey, $step);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Freeze the current resolution into a versioned, citable ScenarioFactSet row
     * (§A.8.2). Persists only non-null resolutions. The fact_set_hash is an
     * HMAC-SHA256 keyed on config('app.key') — money values have a small
     * brute-forceable search space, so a bare SHA-256 of the unencrypted hash
     * column would be attackable.
     */
    public function snapshotFactSet(User $user, int $taxYear): ScenarioFactSet
    {
        $resolved = array_filter(
            $this->resolveAll($user, $taxYear),
            static fn ($r) => $r !== null
        );

        return ScenarioFactSet::create([
            'user_id' => $user->id,
            'tax_year' => $taxYear,
            'fact_set_hash' => $this->computeFactSetHash($resolved),
            // Manual json_encode into the 'encrypted' TEXT cast (InterviewSession
            // .assertions precedent — avoids encrypted:array double-encode).
            'resolved_facts' => json_encode($resolved),
        ]);
    }

    /**
     * Staleness check mirroring IncomeOptimizerDataAssemblerService::isStale():
     * recompute the current hash and compare to the stored one. Fact supersession
     * flips this true (D9.2 / M9 recompute affordance).
     */
    public function isStale(ScenarioFactSet $set): bool
    {
        $user = $set->user;
        if ($user === null) {
            return true;
        }

        $resolved = array_filter(
            $this->resolveAll($user, (int) $set->tax_year),
            static fn ($r) => $r !== null
        );

        return $this->computeFactSetHash($resolved) !== $set->fact_set_hash;
    }

    // ─── Chain walking ────────────────────────────────────────────────────────

    private function walkStep(User $user, int $taxYear, string $canonicalKey, string $step): ?array
    {
        if ($step === 'fact') {
            return $this->resolveFromFact($user, $taxYear, $canonicalKey);
        }

        // Terminal: 'ask' and 'ask:{fact_key}' mean the fact must be interview-asked.
        if ($step === 'ask' || str_starts_with($step, 'ask:')) {
            return null;
        }

        if (str_starts_with($step, 'snapshot:')) {
            return $this->resolveFromSnapshot($user, $taxYear, $canonicalKey, substr($step, 9));
        }

        if (str_starts_with($step, 'profile:')) {
            return $this->resolveFromProfile($user, $canonicalKey, substr($step, 8));
        }

        if (str_starts_with($step, 'derive:')) {
            return $this->resolveFromDerivation($user, $taxYear, $canonicalKey, substr($step, 7));
        }

        return null;
    }

    /**
     * Confirmed-fact lookup: canonical key first, then aliases (§A.1.3), each
     * year-scoped before unscoped. currentFact() already applies the D4 confirm
     * gate (unconfirmed document_extraction proposals are excluded).
     */
    private function resolveFromFact(User $user, int $taxYear, string $canonicalKey): ?array
    {
        // NOTE: canonical keys contain dots — fetch the whole map and index by the
        // literal string (config() dot-notation would mis-traverse the key).
        $allAliases = (array) config('optimization-objectives.fact_aliases', []);
        $aliases = (array) ($allAliases[$canonicalKey] ?? []);

        foreach (array_merge([$canonicalKey], $aliases) as $key) {
            $fact = UserTaxFact::currentFact($user->id, $key, null, $taxYear)
                ?? UserTaxFact::currentFact($user->id, $key, null, null);

            if ($fact !== null) {
                return $this->makeResolved(
                    $canonicalKey,
                    $fact->value,
                    $this->inferValueType($canonicalKey),
                    'fact',
                    'fact:'.$fact->id,
                    in_array($fact->source_type, self::CONFIRMED_SOURCE_TYPES, true),
                );
            }
        }

        return null;
    }

    /**
     * Read an IncomeOptimizationProfile snapshot column. Supports composite sums
     * ('w2_wages+self_employment_income'). Snapshot values are always `known`
     * (confirmed=false) — they are never user-assented (§A.7).
     */
    private function resolveFromSnapshot(User $user, int $taxYear, string $canonicalKey, string $columnSpec): ?array
    {
        $profile = IncomeOptimizationProfile::forUser($user->id)
            ->where('tax_year', $taxYear)
            ->first();

        if ($profile === null) {
            return null;
        }

        if (str_contains($columnSpec, '+')) {
            $sum = 0;
            $hit = false;
            $refs = [];
            foreach (explode('+', $columnSpec) as $col) {
                $val = $profile->{$col};
                if ($val !== null) {
                    $sum += (int) $val;
                    $hit = true;
                    $refs[] = $col;
                }
            }
            if (! $hit) {
                return null;
            }

            return $this->makeResolved(
                $canonicalKey,
                (string) $sum,
                'money_cents',
                'snapshot',
                'snapshot:'.$profile->id.':'.implode('+', $refs),
                false,
            );
        }

        $value = $profile->{$columnSpec};
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }

        return $this->makeResolved(
            $canonicalKey,
            (string) $value,
            $this->inferValueType($canonicalKey),
            'snapshot',
            'snapshot:'.$profile->id.':'.$columnSpec,
            false,
        );
    }

    /**
     * Read a UserFinancialProfile field. filing_status is normalised to the
     * config keys; employment_type / has_hsa are mapped to yes/no. Profile
     * values are `known` (confirmed=false).
     */
    private function resolveFromProfile(User $user, string $canonicalKey, string $field): ?array
    {
        $profile = $user->financialProfile;
        if ($profile === null) {
            return null;
        }

        switch ($field) {
            case 'tax_filing_status':
                $raw = $profile->tax_filing_status;
                if ($raw === null) {
                    return null;
                }

                return $this->makeResolved(
                    $canonicalKey,
                    $this->normaliseFilingStatus($raw),
                    'enum',
                    'profile',
                    'profile:'.$profile->id.':tax_filing_status',
                    false,
                );

            case 'employment_type':
                $raw = $profile->employment_type;
                if ($raw === null) {
                    return null;
                }
                $selfEmployed = in_array(
                    $raw,
                    ['self_employed', '1099_contractor', 'business_owner', 'freelancer'],
                    true,
                );

                return $this->makeResolved(
                    $canonicalKey,
                    $selfEmployed ? 'yes' : 'no',
                    'enum',
                    'profile',
                    'profile:'.$profile->id.':employment_type',
                    false,
                );

            case 'has_hsa':
                $val = $profile->has_hsa;
                if ($val === null) {
                    return null;
                }

                return $this->makeResolved(
                    $canonicalKey,
                    $val ? 'yes' : 'no',
                    'enum',
                    'profile',
                    'profile:'.$profile->id.':has_hsa',
                    false,
                );

            default:
                $val = $profile->{$field} ?? null;
                if ($val === null) {
                    return null;
                }

                return $this->makeResolved(
                    $canonicalKey,
                    (string) $val,
                    $this->inferValueType($canonicalKey),
                    'profile',
                    'profile:'.$profile->id.':'.$field,
                    false,
                );
        }
    }

    // ─── Derivations (§A.6.3, deterministic, config-parameterized) ────────────

    private function resolveFromDerivation(User $user, int $taxYear, string $canonicalKey, string $rule): ?array
    {
        return match ($rule) {
            'age_from_birth_year' => $this->deriveAgeFromBirthYear($user, $taxYear, $canonicalKey),
            'annualize_ytd_gross' => $this->deriveAnnualizeYtd($user, $taxYear, $canonicalKey, 'ytd_gross', 'annualize_ytd_gross'),
            'annualize_ytd_federal_tax' => $this->deriveAnnualizeYtd($user, $taxYear, $canonicalKey, 'ytd_federal_tax', 'annualize_ytd_federal_tax'),
            'per_period_times_frequency' => $this->derivePerPeriodTimesFrequency($user, $taxYear, $canonicalKey),
            'frequency_from_paystub' => $this->deriveFrequencyFromPaystub($user, $taxYear, $canonicalKey),
            'spouse_annual_from_profile' => $this->deriveSpouseAnnualFromProfile($user, $canonicalKey),
            'contribution_pct_from_ytd' => $this->deriveContributionPctFromYtd($user, $taxYear, $canonicalKey),
            // Unimplemented derivations (magi_projection, from_match_formula,
            // prior_year_bonus_*, paystub_gross_pay, annual_gross_over_periods)
            // fall through — the chain continues to its next step.
            default => null,
        };
    }

    /** age = tax_year − birth_year (§A.6.3). */
    private function deriveAgeFromBirthYear(User $user, int $taxYear, string $canonicalKey): ?array
    {
        $birth = $this->resolveFromFact($user, $taxYear, 'person.birth_year');
        if ($birth === null || ! ctype_digit((string) $birth['value'])) {
            return null;
        }

        $age = $taxYear - (int) $birth['value'];
        if ($age < 0) {
            return null;
        }

        return $this->makeResolved(
            $canonicalKey,
            (string) $age,
            'integer',
            'derived',
            'derived:age_from_birth_year('.$birth['source_ref'].')',
            false,
        );
    }

    /** ytd_value ÷ elapsed_year_fraction(pay_date) → annualized cents (§A.6.3). */
    private function deriveAnnualizeYtd(User $user, int $taxYear, string $canonicalKey, string $ytdField, string $ruleId): ?array
    {
        $doc = $this->latestReadyPaystub($user, $taxYear);
        if ($doc === null) {
            return null;
        }

        $fields = $doc->extracted_data['fields'] ?? $doc->extracted_data ?? [];
        $ytd = $this->fieldValue($fields, $ytdField);
        $payDate = $this->fieldValue($fields, 'pay_date');
        if ($ytd === null || $payDate === null) {
            return null;
        }

        try {
            $pd = Carbon::parse((string) $payDate);
        } catch (\Throwable) {
            return null;
        }

        $dayOfYear = $pd->dayOfYear;
        $daysInYear = $pd->daysInYear;
        if ($dayOfYear <= 0 || $daysInYear <= 0) {
            return null;
        }

        $fraction = $dayOfYear / $daysInYear;
        if ($fraction <= 0) {
            return null;
        }

        $annualCents = (int) round(((float) $ytd / $fraction) * 100);

        return $this->makeResolved(
            $canonicalKey,
            (string) $annualCents,
            'money_cents',
            'derived',
            'derived:'.$ruleId.'(doc:'.$doc->id.')',
            false,
        );
    }

    /** per_period_cents × periods_per_year[pay.frequency] (§A.6.3). */
    private function derivePerPeriodTimesFrequency(User $user, int $taxYear, string $canonicalKey): ?array
    {
        // Wired only for employer.federal_withholding: annualize per-paycheck withholding.
        $perPeriod = $this->resolveFromFact($user, $taxYear, 'pay.federal_withholding_per_period_cents');
        $frequency = $this->resolve($user, $taxYear, 'pay.frequency');
        if ($perPeriod === null || $frequency === null) {
            return null;
        }

        $periods = $this->periodsPerYear((string) $frequency['value']);
        if ($periods === null || ! ctype_digit((string) $perPeriod['value'])) {
            return null;
        }

        return $this->makeResolved(
            $canonicalKey,
            (string) ((int) $perPeriod['value'] * $periods),
            'money_cents',
            'derived',
            'derived:per_period_times_frequency('.$perPeriod['source_ref'].'x'.$periods.')',
            false,
        );
    }

    /**
     * Pay-period span days → frequency (§A.6.3 span table). Ambiguous spans
     * (13–16 days without 1st/15th anchors) resolve to null → falls through to ask.
     */
    private function deriveFrequencyFromPaystub(User $user, int $taxYear, string $canonicalKey): ?array
    {
        $doc = $this->latestReadyPaystub($user, $taxYear);
        if ($doc === null) {
            return null;
        }

        $fields = $doc->extracted_data['fields'] ?? $doc->extracted_data ?? [];
        $start = $this->fieldValue($fields, 'pay_period_start');
        $end = $this->fieldValue($fields, 'pay_period_end');
        if ($start === null || $end === null) {
            return null;
        }

        try {
            $s = Carbon::parse((string) $start);
            $e = Carbon::parse((string) $end);
        } catch (\Throwable) {
            return null;
        }

        $span = $s->diffInDays($e) + 1; // inclusive span
        $anchored = ($s->day === 1 && $e->day === 15)
            || ($s->day === 16 && $e->isLastOfMonth());

        $frequency = match (true) {
            $span >= 6 && $span <= 8 => 'weekly',
            $span >= 27 && $span <= 32 => 'monthly',
            $anchored && $span >= 14 && $span <= 16 => 'semimonthly',
            $span >= 12 && $span <= 14 => 'biweekly',
            default => null, // ambiguous → ask
        };

        if ($frequency === null) {
            return null;
        }

        return $this->makeResolved(
            $canonicalKey,
            $frequency,
            'enum',
            'derived',
            'derived:frequency_from_paystub(doc:'.$doc->id.')',
            false,
        );
    }

    /** monthly spouse_income × 12 → annual cents (§A.6.3). */
    private function deriveSpouseAnnualFromProfile(User $user, string $canonicalKey): ?array
    {
        $profile = $user->financialProfile;
        if ($profile === null || $profile->spouse_income === null) {
            return null;
        }

        $monthlyDollars = (float) $profile->spouse_income;
        if ($monthlyDollars <= 0) {
            return null;
        }

        return $this->makeResolved(
            $canonicalKey,
            (string) ((int) round($monthlyDollars * 12 * 100)),
            'money_cents',
            'derived',
            'derived:spouse_annual_from_profile(profile:'.$profile->id.':spouse_income)',
            false,
        );
    }

    /** 401k_ytd ÷ annual_gross → contribution percent (§A.6.3). */
    private function deriveContributionPctFromYtd(User $user, int $taxYear, string $canonicalKey): ?array
    {
        $ytd = $this->resolve($user, $taxYear, 'retirement.traditional_401k_ytd_cents');
        $gross = $this->resolve($user, $taxYear, 'income.annual_gross_cents');
        if ($ytd === null || $gross === null) {
            return null;
        }

        $den = (int) $gross['value'];
        if ($den <= 0) {
            return null;
        }

        return $this->makeResolved(
            $canonicalKey,
            (string) ((int) round((int) $ytd['value'] / $den * 100)),
            'integer',
            'derived',
            'derived:contribution_pct_from_ytd('.$ytd['source_ref'].'/'.$gross['source_ref'].')',
            false,
        );
    }

    // ─── Hashing ──────────────────────────────────────────────────────────────

    /**
     * HMAC-SHA256 over canonical_json(fact_key => [source_ref, value]) keyed on
     * config('app.key') (§A.8.2). Keys are sorted for a stable canonical form;
     * resolved_at is deliberately excluded so identical resolutions hash equal.
     *
     * @param  array<string, array<string, mixed>>  $resolvedFacts
     */
    private function computeFactSetHash(array $resolvedFacts): string
    {
        ksort($resolvedFacts);

        $canonical = [];
        foreach ($resolvedFacts as $key => $fact) {
            $canonical[$key] = [$fact['source_ref'] ?? null, $fact['value'] ?? null];
        }

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $json, (string) config('app.key'));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function makeResolved(
        string $factKey,
        ?string $value,
        string $valueType,
        string $source,
        string $sourceRef,
        bool $confirmed,
    ): array {
        return [
            'fact_key' => $factKey,
            'value' => $value,
            'value_type' => $valueType,
            'source' => $source,
            'source_ref' => $sourceRef,
            'confirmed' => $confirmed,
            'resolved_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Nested-with-fallback field read (mirrors the assembler's PayStub arm):
     * prefers $fields[name]['value'], falls back to a flat $fields[name].
     */
    private function fieldValue(array $fields, string $name): mixed
    {
        if (! array_key_exists($name, $fields)) {
            return null;
        }

        $field = $fields[$name];

        return is_array($field) ? ($field['value'] ?? null) : $field;
    }

    private function latestReadyPaystub(User $user, int $taxYear): ?TaxDocument
    {
        return TaxDocument::forUser($user->id)
            ->byYear($taxYear)
            ->byStatus(DocumentStatus::Ready)
            ->where('category', TaxDocumentCategory::PayStub->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function periodsPerYear(string $frequency): ?int
    {
        $map = (array) config('optimization-objectives.pay_periods_per_year', []);

        return isset($map[$frequency]) ? (int) $map[$frequency] : null;
    }

    /**
     * Best-effort value_type tag for a canonical key (presentational metadata;
     * *_cents → money_cents, enums/years/integers by key). Never affects resolution.
     */
    private function inferValueType(string $key): string
    {
        if (str_ends_with($key, '_cents')) {
            return 'money_cents';
        }

        return match ($key) {
            'person.birth_year' => 'year',
            'profile.filing_status', 'w4.filing_status', 'pay.frequency', 'hsa.coverage_type',
            'has_self_employment', 'health.hsa_eligible', 'employer.has_401k',
            'finance.is_cash_constrained', 'spouse.covered_by_retirement_plan' => 'enum',
            'family.dependents_count', 'family.qualifying_children_under_17', 'w4.dependents_claimed',
            'retirement.target_age', 'employer.match_pct', 'employer.match_threshold_pct',
            'employer.contribution_pct', 'bonus.expected_month' => 'integer',
            default => 'string',
        };
    }

    /**
     * Filing-status normaliser — mirrors
     * IncomeOptimizerDataAssemblerService::normaliseFilingStatus() (the reference).
     */
    private function normaliseFilingStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ($status) {
            'married_jointly', 'married_filing_jointly' => 'married_joint',
            'married_separately', 'married_filing_separately' => 'married_separate',
            'head_of_household' => 'head_of_household',
            'single' => 'single',
            default => $status,
        };
    }

    /**
     * Union of canonical keys across the readiness objectives (scenario-only
     * domains excluded, mirroring ObjectiveReadinessService iteration, §A.8.1).
     *
     * @return string[]
     */
    private function allCanonicalKeys(): array
    {
        $keys = [];
        foreach ((array) config('optimization-objectives.objectives', []) as $objective) {
            if (! empty($objective['is_scenario_domain'])) {
                continue;
            }
            foreach ((array) ($objective['facts'] ?? []) as $spec) {
                if (isset($spec['canonical_key'])) {
                    $keys[] = $spec['canonical_key'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * First fact-spec whose canonical_key matches, across all objectives. Shared
     * facts declare identical chains, so the first match is authoritative.
     *
     * @return array<string, mixed>|null
     */
    private function factSpec(string $canonicalKey): ?array
    {
        foreach ((array) config('optimization-objectives.objectives', []) as $objective) {
            foreach ((array) ($objective['facts'] ?? []) as $spec) {
                if (($spec['canonical_key'] ?? null) === $canonicalKey) {
                    return $spec;
                }
            }
        }

        return null;
    }
}
