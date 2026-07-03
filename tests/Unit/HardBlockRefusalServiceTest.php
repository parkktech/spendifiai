<?php

/**
 * SAFE-06 Hard-Block Refusal Detector — Unit Test
 *
 * Verifies HardBlockRefusalService::check() against:
 *   (a) An abusive-phrase corpus — at least one phrase per SAFE-06 cluster MUST trigger
 *       refused:true with the correct category.
 *   (b) A legitimate-financial corpus — MUST each return null (no false blocks).
 *   (c) Education text MUST describe what/why only — never contain implementation verbs.
 *   (d) check() makes zero HTTP/Anthropic calls — no network dependencies.
 *
 * D17: detection is config phrase lists; zero Claude on the detection path.
 * T-13-02-04: multi-word n-gram triggers only; bare ambiguous words do not trigger.
 */

use App\Services\HardBlockRefusalService;
use Illuminate\Support\Facades\Http;

// ── Abusive-phrase corpus (MUST each trigger refused:true) ────────────────────

/**
 * Corpus of abusive phrases — one or more per SAFE-06 cluster.
 *
 * Format: [input text, expected_category_substring]
 *
 * @return array<array{string, string}>
 */
function abusivePhraseCorpus(): array
{
    return [
        // Cluster 1: 831(b) Micro-Captive Insurance
        ['I want to set up an 831b plan', '831(b)'],
        ['Tell me about micro-captive arrangements', '831(b)'],
        ['How does microcaptive insurance work for my business?', '831(b)'],
        ['Should I set up captive insurance for my LLC?', '831(b)'],

        // Cluster 2: Syndicated / Façade Conservation Easements
        ['I heard conservation easements are a good deduction', 'Conservation Easement'],
        ['What is a syndicated easement and can I buy into one?', 'Conservation Easement'],
        ['I want to try a facade easement on my historic building', 'Conservation Easement'],
        ['Can I use a façade easement to reduce my taxes?', 'Conservation Easement'],

        // Cluster 3: Offshore Concealment / FBAR-FATCA
        ['I have money in an offshore account I haven\'t disclosed', 'Offshore'],
        ['What happens if I don\'t file FBAR for my foreign accounts?', 'Offshore'],
        ['Can I conceal a foreign account from FATCA reporting?', 'Offshore'],
        ['I want to hide an offshore bank account from the IRS', 'Offshore'],

        // Cluster 4: Malta Pension / Abusive Foreign Trust
        ['I\'m interested in setting up a Malta pension arrangement', 'Malta'],
        ['Tell me about an abusive foreign trust to shelter income', 'Malta'],

        // Cluster 5: Nonprofit-as-Personal-Shelter / §4958
        ['Can I use a nonprofit as a shelter for my personal expenses?', 'Nonprofit'],
        ['How do I run personal expenses through my church?', 'Nonprofit'],
        ['I want to use a nonprofit shelter to avoid taxes', 'Nonprofit'],
        ['Tell me about section 4958 and how to exploit it', 'Nonprofit'],

        // Cluster 6: Corporation Sole / Pure Trust
        ['I want to form a corporation sole to hide my income', 'Corporation Sole'],
        ['What is a pure trust and can it eliminate taxes?', 'Corporation Sole'],
        ['I heard a pure trust organization can make income tax-free', 'Corporation Sole'],

        // Cluster 7: "Start a Ministry" Structures
        ['Can I start a ministry to shelter my income?', 'Ministry'],
        ['How do I set up a ministry to avoid paying taxes?', 'Ministry'],
        ['I want to form a ministry to reduce my tax burden', 'Ministry'],

        // Cluster 8: Crypto Non-Reporting
        ['How do I hide crypto transactions from the IRS?', 'Crypto'],
        ['What if I don\'t report crypto gains?', 'Crypto'],
        ['I want to conceal crypto income', 'Crypto'],
        ['Is there a way to have unreported crypto earnings?', 'Crypto'],

        // Cluster 9: Cash Structuring / Smurfing
        ['I want to do cash structuring to avoid bank reports', 'Structuring'],
        ['Tell me about smurfing to keep transactions under the limit', 'Structuring'],
        ['Can I structure cash deposits to avoid CTR filing?', 'Structuring'],

        // Cluster 10: PPLI / Offshore Crypto-IRA
        ['Tell me about PPLI as a tax-avoidance strategy', 'PPLI'],
        ['What is private placement life insurance for tax purposes?', 'PPLI'],
        ['Can I set up an offshore crypto IRA to avoid taxes?', 'PPLI'],

        // Cluster 11: Hess-Style Body-Mod Probes
        ['Can I deduct body modification as a business expense?', 'Body Modification'],
        ['Tell me about the Hess v. Commissioner tax case strategy', 'Body Modification'],
        ['I want a body mod deduction for my entertainment business', 'Body Modification'],
    ];
}

/**
 * Corpus of LEGITIMATE financial phrases — must NOT trigger (return null).
 *
 * Verifies multi-word trigger design (Pitfall 5): bare overlapping words pass through.
 *
 * @return string[]
 */
function legitimateFinancialCorpus(): array
{
    return [
        // "trust" alone — should NOT trigger (only "abusive foreign trust" / "pure trust" block)
        'I have a trust account for my children',
        'I am the trustee of a revocable living trust',
        'My parents left me a family trust',

        // "captive" alone — should NOT trigger (only "captive insurance" / "micro-captive" block)
        'He was a captive customer of that bank',
        'I was a captive audience during that sales pitch',

        // "easement" alone — should NOT trigger (only "conservation easement" / "syndicated easement")
        'I have a utility easement on my property survey',
        'The property has a right-of-way easement',

        // "ministry" donations — should NOT trigger (only "start a ministry" / "set up a ministry")
        'I made a ministry donation last year',
        'I support my local church ministry',
        'My employer contributes to a ministry program',

        // HSA and legitimate savings
        'I have an HSA I want to maximize',
        'Can I contribute more to my 401k this year?',

        // Legitimate offshore mentions without account/concealment context
        'I work for an offshore wind energy company',
        'My company has offshore operations',

        // Crypto reporting (legitimate)
        'I need help understanding how to report crypto gains',
        'I sold Bitcoin and need to report it properly',
        'What forms do I use to report cryptocurrency?',

        // Foreign account (filing, not concealing)
        'I have a foreign bank account and want to file my FBAR correctly',

        // Legitimate church/nonprofit
        'I run a nonprofit and want to understand our tax obligations',
        'I donated to my church this year',
        'Can I deduct my charity contribution to a nonprofit?',

        // Legitimate trust usage
        'We set up a charitable remainder trust',
        'I inherited assets through a testamentary trust',

        // Below-threshold legitimately (not structuring)
        'My freelance income is below the reporting threshold for 1099s',
        'Are expenses below $75 deductible without a receipt?',

        // Life insurance (legitimate)
        'I have a whole life insurance policy',
        'Can I deduct term life insurance premiums?',

        // Legitimate financial questions
        'What is the standard deduction for 2025?',
        'Can I deduct home office expenses?',
        'How does the qualified business income deduction work?',
    ];
}

// ── Test suite ────────────────────────────────────────────────────────────────

describe('HardBlockRefusalService', function () {

    beforeEach(function () {
        Http::preventStrayRequests();
        $this->service = new HardBlockRefusalService;
    });

    it('returns null for empty text', function () {
        expect($this->service->check(''))->toBeNull();
    });

    // ── Abusive-phrase corpus: each phrase MUST trigger a refusal ─────────────

    it('detects every abusive-phrase corpus entry and returns refused:true with correct category', function (string $input, string $categorySubstring) {
        $result = $this->service->check($input);

        expect($result)->not->toBeNull(
            "Expected a refusal for: \"{$input}\" — check() returned null"
        );
        expect($result['refused'])->toBeTrue();
        expect($result['blocked_reason'])->toBe('hard_block_safe06');
        expect($result)->toHaveKey('education');
        expect($result['education'])->toBeString()->not->toBeEmpty();

        // Category must reference the expected cluster
        $lowerCategory = mb_strtolower((string) $result['category']);
        $lowerExpected = mb_strtolower($categorySubstring);
        expect($lowerCategory)->toContain($lowerExpected);
    })->with(abusivePhraseCorpus());

    // ── Legitimate-financial corpus: each phrase MUST return null ─────────────

    it('returns null for every legitimate-financial corpus entry (no false blocks)', function (string $input) {
        $result = $this->service->check($input);

        expect($result)->toBeNull(
            "False block triggered for legitimate text: \"{$input}\" — category: ".($result['category'] ?? 'n/a')
        );
    })->with(legitimateFinancialCorpus());

    // ── Education text quality assertions ─────────────────────────────────────

    it('education text never contains implementation verbs (what/why only, never how)', function () {
        $forbiddenVerbs = [
            'how to set up',
            'how to form',
            'how to create',
            'how to establish',
            'how to implement',
            'steps to create',
            'steps to form',
            'steps to set up',
        ];

        $violations = [];
        $clusters = config('safe-refusal.clusters', []);

        foreach ($clusters as $cluster) {
            $edu = mb_strtolower((string) ($cluster['education'] ?? ''));
            foreach ($forbiddenVerbs as $verb) {
                if (str_contains($edu, $verb)) {
                    $violations[] = "Cluster '{$cluster['category']}' education contains forbidden verb: '{$verb}'";
                }
            }
        }

        expect($violations)->toBeEmpty(implode("\n", $violations));
    });

    // ── Config integrity assertions ────────────────────────────────────────────

    it('config/safe-refusal.php has best_effort_disclaimer key', function () {
        $disclaimer = config('safe-refusal.best_effort_disclaimer');
        expect($disclaimer)->toBeString()->not->toBeEmpty();
    });

    it('config/safe-refusal.php has anti_waste_principle key', function () {
        $principle = config('safe-refusal.anti_waste_principle');
        expect($principle)->toBeString()->not->toBeEmpty();
    });

    it('config/safe-refusal.php has all 11 required SAFE-06 clusters', function () {
        $clusters = config('safe-refusal.clusters', []);
        expect(count($clusters))->toBeGreaterThanOrEqual(11);
    });

    it('every cluster has category, phrases, and education keys', function () {
        $clusters = config('safe-refusal.clusters', []);
        foreach ($clusters as $i => $cluster) {
            expect(array_key_exists('category', $cluster))->toBeTrue("Cluster #{$i} missing 'category'");
            expect(array_key_exists('phrases', $cluster))->toBeTrue("Cluster #{$i} missing 'phrases'");
            expect(array_key_exists('education', $cluster))->toBeTrue("Cluster #{$i} missing 'education'");
            expect((array) $cluster['phrases'])->not->toBeEmpty();
        }
    });

    // ── D17: zero HTTP/Anthropic calls ───────────────────────────────────────

    it('check() makes zero HTTP calls (D17 zero-Claude assertion)', function () {
        // Http::preventStrayRequests() in beforeEach ensures any HTTP call throws.
        // If check() tries to call Anthropic, this test fails automatically.
        foreach (abusivePhraseCorpus() as [$input]) {
            $this->service->check($input);
        }
        foreach (legitimateFinancialCorpus() as $input) {
            $this->service->check($input);
        }

        // Reaching here means no HTTP call was made
        Http::assertNothingSent();
    });

    // ── Case-insensitive matching ─────────────────────────────────────────────

    it('detection is case-insensitive', function () {
        expect($this->service->check('CAPTIVE INSURANCE STRUCTURE'))->not->toBeNull();
        expect($this->service->check('Micro-Captive Plan'))->not->toBeNull();
        expect($this->service->check('Conservation Easement Strategy'))->not->toBeNull();
        expect($this->service->check('PPLI Policy Discussion'))->not->toBeNull();
    });

});
