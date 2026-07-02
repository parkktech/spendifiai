# Transaction Detection Spec v2 DELTA — Distilled Planning Reference (Sections 6–16 ONLY)

> Source: `/home/spendifi/public_html/transaction-detection-spec (1).md` — sections 6–16, absent from v1.
> Companion: `transaction-detection-distilled.md` (v1, §0–5 — pipeline, materiality gates, category detectors, question engine, output contract). Do NOT duplicate v1 content here; this file covers ONLY the v2 additions.
> Purpose: Phase 11/12/13 planning input. Every detection rule, trigger pattern, schema field, sweep, guard, and build-order note preserved verbatim in intent.
>
> **Safety boundary (unchanged, applies to everything below):** educational-only ("may / could / consider"), dollars computed exclusively by `TaxRulesEngineService` / `config/tax-rules.php` (Claude never computes), locked out-of-scope list in `.planning/REQUIREMENTS.md` still binds. Several sections below (§12 guards, §13 gambling, §14 notice-response) push against that boundary and are flagged as owner decisions, not silently adopted.

---

## §6. Second Data Plane: Paystub & Benefits Ingestion

**Premise:** bank data sees *spending*, not **unelected benefits**. For pure W-2 users this plane outperforms transaction analysis entirely.

**Trigger cadence:** request paystub + benefits-guide upload at (a) onboarding and (b) each open-enrollment season.

**Parse targets (full list — each is a distinct extraction field):**
1. 401(k)/403(b)/457(b) availability + current deferral %
2. Employer match formula vs actual contribution → **unclaimed match = alert**
3. After-tax 401(k) + in-plan conversion availability → **mega backdoor gate**
4. HDHP/HSA status
5. FSA / DCFSA elections
6. ESPP terms and participation
7. NQDC eligibility
8. §127 education/student-loan benefit
9. Commuter benefits
10. Employer Trump Account contributions
11. Paystub **state withholding vs detected residence** cross-check → multi-state flag

**Infra mapping:** v2.0 Tax Document Vault (encrypted storage, signed URLs, immutable audit trail, two-pass AI extraction, 25 form types) is the ingestion substrate — check whether "paystub" and "benefits guide" are among the 25 supported form types; if not, this is new `TaxDocumentCategory` cases + new extraction schemas (Phase 12 doc-intake territory). Extracted facts should land in `IncomeOptimizationProfile` (encrypted per-user+tax-year) so `answerableFields()` skip-logic suppresses questions already answered by the paystub. Deferral limits / phaseout math → `config/tax-rules.php` + `TaxRulesEngineService`.

---

## §7. Life-Event Trigger Engine

Events unlock strategy windows that transaction categories never surface. Two acquisition paths:

**A. Detect from transaction data (each is a detector rule):**
| Signal | Event inference | Strategy window unlocked |
|---|---|---|
| Payroll deposit **stops** | Job loss / sabbatical | Roth conversion + 0% gain harvesting + ACA window |
| New mortgage payment appears | Home purchase | (basis ledger start, mortgage-interest context) |
| Escrow/title company **inflow** | Home sale | §121 exclusion + basis-ledger settlement |
| Marketplace premium payments | ACA enrollee | ACA cliff monitor activation |

**B. Ask annually (interview items — cannot be detected):**
- Marriage / divorce
- Birth / adoption
- Child turning **17 / 19 / 24** (credit cliffs, kiddie tax)
- College start
- **State move** → build a domicile evidence file: license, voter registration, vehicle registration, lease/deed, day count, old-ties-severed
- Inheritance / death of parent → **step-up documentation NOW** (date-of-death valuations are easy today, expensive to reconstruct later)
- Medicare enrollment → HSA contribution **stop** (6-month lookback trap)
- RSU vest years
- Large gains

**Infra mapping:** detection path A reuses `IncomeDetectorService` (payroll regularity), `HousingDetectionService` (mortgage detection), `SubscriptionDetectorService` recurrence (marketplace premiums). Path B = annual interview questions via Phase 11 interview orchestration + `AIQuestion`. Roadmap position: module 7 (§10) — the full engine is post-Phase-11, but the four data-detectable triggers are cheap Phase 11 detector candidates since their underlying signals already exist.

---

## §8. Penalty-Prevention Sweeps (forensic layer — runs CONTINUOUSLY)

Positioning: *"We stop tax problems before they exist."* This layer justifies the subscription even in years with no new strategies (retention — roadmap slot #2). Full sweep list (each is a distinct continuous rule):

1. **Under-withholding / missed estimates vs safe harbor**
2. **Missed or late 83(b)** — 30-day HARD clock from any detected equity grant
3. **Late S-corp election** — Form 2553 window
4. **1099-K / 1099-DA / 1099-NEC mismatch** vs detected deposits
5. **Missed RMDs**
6. **Excess IRA/Roth/HSA contributions** — income-phaseout crossings
7. **Roth income-limit breach** — recharacterize before deadline
8. **HSA contributions after Medicare enrollment**
9. **Wash sales across accounts**
10. **Commingling score** (business/personal account mixing)
11. **Payroll deposit failures** (S-corps)
12. **State nexus / sales-tax exposure** for e-commerce sellers
13. **ACA advance-credit overrun** — NO repayment cap post-2025

**Infra mapping:** sweeps are scheduled detectors — natural fit for the existing scheduler (`routes/console.php`) + a new sweep service in Phase 11's detection layer, gated by user activity like the existing 28-day-threshold AI-job gating. Safe-harbor and phaseout thresholds → `config/tax-rules.php`. Findings → `OptimizationFinding`. Note: several sweeps need data planes not yet ingested (RMDs, wash sales, 1099 forms) — scope Phase 11 to the transaction-observable subset (1, 2-partial, 4, 10, 13-partial via marketplace premiums) and defer the rest to the paystub/prior-return planes.

---

## §9. Rule Schema — Expiration Validator (ARCHITECTURE — shapes Phase 11 plan structure)

**Every rule in the engine carries effective dating; the engine suppresses or flags stale rules automatically.** Canonical schema (verbatim):

```json
{
  "rule_id": "tips_deduction",
  "authority": "IRC §224 (OBBBA)",
  "effective_start": "2025-01-01",
  "effective_end": "2028-12-31",
  "phaseouts": {"magi_single": 150000, "magi_mfj": 300000},
  "inflation_adjusted": false,
  "source_url": "irs.gov/...",
  "last_verified": "2026-07-01",
  "status": "verified | needs_review | expired | expired_pending_extension",
  "band": "auto | conditional | specialist | suppress | hard_block"
}
```

Field inventory: `rule_id`, `authority`, `effective_start`, `effective_end`, `phaseouts` (keyed by filing-status MAGI), `inflation_adjusted` (bool), `source_url`, `last_verified`, `status` (4-state enum), `band` (5-state enum: auto / conditional / specialist / suppress / hard_block).

**Known sunset calendar to seed:**
| Rule | Sunset / status |
|---|---|
| Tips / OT / senior / auto-loan deductions | 2028 |
| SALT $40K cap | 2029 |
| QOF mandatory recognition | end of 2026 |
| Enhanced ACA credits | ALREADY expired — cliff active |
| Residential energy credits | expired 2025 — amendment scanner only |
| Annual inflation adjustments | every rule with dollar figures |

**Infra mapping:** this is a direct extension of `config/tax-rules.php` — every existing constant gains effective-dating metadata, and `TaxRulesEngineService` gains a validator that (a) suppresses expired rules, (b) surfaces `needs_review` when `last_verified` is stale, (c) enforces `band` before any finding renders. The `band` enum should also gate Phase 13 hard-block behavior. Phase 11 plans should adopt this schema for every detector/sweep rule from day one — retrofitting effective dating is expensive.

---

## §10. Module Roadmap (build order by user value ÷ effort — ARCHITECTURE guidance)

Verbatim ordering:
1. **Retroactive scanners + basis ledger** (onboarding wow)
2. **Penalty-prevention sweeps** (retention)
3. **Transaction hypothesis engine, top 10 categories** (spec §2 — v1 content)
4. **Paystub/benefits plane + W-2 benefit arbitrage**
5. **ACA cliff monitor** (small persona, extreme per-user value)
6. **Entity decision tree + S-corp analyzer**
7. **Life-event engine**
8. **Persona packs:** public-sector 457 stacking, travel-worker tax home, students, caregivers
9. **Documentation vault + pro-export**
10. **Specialist routers:** expat/immigrant, clergy, estate, PPLI/QSBS

**Planning note:** this ordering is value÷effort, owner-authored. Phase 11 (detection + interview + AI-feed) maps naturally to items 1–3; Phase 12 (report + doc intake + nav) touches 4 and 9 (vault already exists from v2.0 — item 9 here means the *capture-prompt + pro-export packaging* layer); items 5–8 and 10 are post-v2.1 milestone candidates. Do not invert this order in phase plans without owner sign-off.

---

## §11. Third Data Plane: Prior-Year Return Ingestion

Parse the uploaded 1040 + all schedules at onboarding. Outputs (each a distinct feature):

1. **Carryforward tracker (HEADLINE feature):** capital loss, passive loss (**per-activity, Form 8582**), NOL, charitable, foreign tax credit, and AMT credit carryforwards — routinely *lost* when users switch preparers/software. Recovering a forgotten carryforward = found money on par with the solar scanner (v1 §retroactive).
2. **Missed-item diff:** SE health insurance, QBI, credits — prior return vs detected facts.
3. **Method elections on record** (mileage vs actual, home office method, lot-ID method) → seed the profile graph so the engine never suggests switching methods blindly.
4. **Depreciation schedules** → continue, don't restart.
5. **Prior AGI / liability** → estimated-tax **safe-harbor computation** (feeds sweep §8.1).

**Infra mapping:** the v2.0 Tax Document Vault's two-pass extraction is the parse substrate (1040 is presumably among the 25 form types — verify; Schedules + Form 8582 + depreciation schedules likely need new extraction schemas). Extracted carryforwards/elections → `IncomeOptimizationProfile` fields (Phase 10 model, encrypted, per tax-year). Safe-harbor math → `TaxRulesEngineService`. Disposition: Phase 12 doc-intake extends form coverage; the carryforward tracker as a user-facing feature is a future-milestone headline.

---

## §12. High-Stakes Irreversible-Moment Guards (INTERRUPT-CLASS alerts) — LIABILITY-SENSITIVE

**Some detections warrant interrupting the user, not queuing a suggestion.** These are a new alert *class* above the normal findings feed. Full guard list (each is a distinct trigger rule):

1. **401(k) rollover initiated at public-company employer → NUA check BEFORE rollover completes** — rolling to an IRA permanently destroys the NUA option.
2. **Equity grant detected → 83(b) 30-day clock** (hard deadline countdown).
3. **Large contractor deposit → financing-structure question** (HELOC vs unsecured) *before signing*.
4. **Marketplace enrollee trending over the ACA cliff mid-year.**
5. **Roth conversion queued in an IRMAA lookback year.**
6. **HSA contributions continuing within 6 months of Medicare enrollment.**
7. **Crypto/stock sale queued that crosses a cliff** (ACA, IRMAA, EITC investment-income limit).
8. **Entity classification change → 60-month lock confirmation.**

**Liability note (Phase 13 / owner decision):** interrupt-class urgency framing ("before rollover completes", "before signing") is the closest this product gets to directive advice. Wording must stay educational ("this often benefits from a professional review *before* it becomes irreversible") and every guard should terminate in a pro-export path, not an instruction. A missed guard is also a liability narrative ("your app didn't warn me") — disclaimers must state guards are best-effort, not monitoring guarantees. UX: SpendifiAI has NO push-notification system yet (v-next) — "interrupt-class" initially means top-of-dashboard blocking banner + email; true interrupts are a future capability. Several triggers (rollover "initiated", conversion/sale "queued") require signals SpendifiAI cannot see in bank data — realistic v2.1 versions are transaction-inferred (equity-grant deposit patterns, marketplace premiums, HSA + Medicare-age heuristics) plus interview-asserted intent.

---

## §13. Gambling Detection Module — LIABILITY-SENSITIVE

**Detection signals:** DraftKings, FanDuel, BetMGM, Caesars merchant matches; casino ATM patterns.

**Tax rule:** from 2026, only **90% of losses** are deductible → break-even bettors owe tax on **phantom income**.

**Features (full list):**
1. Running **session log** — the IRS-preferred method for slots/apps
2. **W-2G reconciliation** vs detected deposits
3. Year-end **phantom-income exposure estimate**
4. **Professional-status analysis** at scale
5. **The honest warning most apps won't show:** heavy recurring gambling spend also feeds the **wellbeing/risk lens**, not just the tax lens.

**Liability note (owner decision):** the wellbeing/risk lens is a product-values call — surfacing possible problem gambling to a user is sensitive (tone, opt-out, no moralizing) and outside pure tax scope; flag for explicit owner sign-off before building. Phantom-income estimates are dollar computations → `TaxRulesEngineService` only, 90%-of-losses constant → `config/tax-rules.php` with §9 effective dating (`effective_start: 2026-01-01`). Merchant signals fit the existing detector/merchant-normalization pipeline (Phase 11-compatible detector; full module = future milestone).

---

## §14. IRS Notice-Response Module — LIABILITY-SENSITIVE

**Flow:** user photographs any IRS/state letter → classify (CP2000 matching, balance due, ID verify, audit) → rebut matching errors from held data (**basis records defeat most CP2000s**) → generate the response letter + evidence packet → OR route to the pro network.

**Positioning:** "We handle the scary letter" — likely the strongest retention feature in the product.

**Liability note (owner decision REQUIRED):** *generating response letters to the IRS* is qualitatively different from educational suggestions — it edges into representation/practice territory (Circular 230). The defensible v2.1-adjacent shape is: classify the notice + assemble the evidence packet + route to a licensed pro (consistent with §15's "implementation terminates at a licensed human"). Auto-generated rebuttal letters sent by the user should be flagged for legal review before any build. Photo intake + classification maps onto the vault's extraction pipeline (Phase 12 doc-intake pattern); the module itself is a future milestone.

---

## §15. Business-Model / Liability Layer (Phase 13 framing + business decisions)

**Core construct — the vetted CPA/EA network** is simultaneously three things:
1. **Monetization** — per-packet or revenue-share on pro referrals
2. **Circular 230 posture** — the product sells *prepared fact patterns with documentation* to professionals who approve and implement; it does **not** sell advice
3. **Liability firewall** — every implementation path terminates at a licensed human

**Revenue model:** subscription (scanners, monitors, vault) + per-packet / revenue-share pro referrals + premium tiers for entity owners.

**Hard product rules:**
- Every recommendation surface carries the educational-scenario disclaimer (non-dismissable per existing locked scope).
- Every implementation path terminates at a licensed human.

**Data posture requirements (compliance roadmap):** GLBA safeguards · IRC **§7216** handling (tax-return-information use/disclosure consent rules) · **SOC 2 before scale**. Rationale: bank data + tax data is the most sensitive combination a consumer app can hold.

**Infra mapping:** Phase 13 (safety/legal hardening) should encode the disclaimer + terminate-at-human rules; the CPA/EA network itself, revenue mechanics, and SOC 2 program are owner business decisions outside engineering phases — flag, don't build. §7216 consent language likely needs attorney review before any pro-export feature ships.

---

## §16. Year-End Proactive Engine (Q4 Module) — FLAGSHIP

The feature the accumulated profile graph exists to power. Full strategy content lives in **playbook §8B** (see tax-strategy distillations).

**Pipeline (ordered):**
```
YTD income projection (payroll + business inflows + realized gains + crypto)
  → liability-vs-payments gap
  → marginal-rate trajectory (this year vs next)
  → cliff-proximity scan (ACA, IRMAA, QBI, tips/OT, 0% LTCG, EITC)
  → PURCHASE-LIST INTERVIEW
  → ranked timing recommendations
  → hard-deadline calendar
  → executed-action log for the filing packet
```

**Purchase-list interview — the ANTI-WASTE GUARD (hard product rules):**
- Question: "What equipment, vehicles, or big expenses are you planning in the next 6–12 months anyway?"
- The engine only ever **re-times planned spending** across the year boundary — it never induces spending.
- **Hard rule:** no card may present spending as net savings. Every accelerate-purchase card renders `tax_saved` AND `net_cash_cost` side by side.
- If the user hasn't asserted a pre-existing need, **the card doesn't render at all**.

**Equipment gates (all must pass):**
1. Placed-in-service ≤ **Dec 31** feasibility — `lead_time_days` field; alerts fire **Oct 1**
2. §179-vs-bonus selection — **mid-quarter check: Q4 asset ratio > 40% → prefer bonus**
3. Business-use assertion + logging starts at delivery
4. Listed-property **>50%** business-use rule
5. GVWR branch (vehicle weight class)
6. §179 business-income cap vs bonus-NOL branch

**Solar/energy gate:**
- Primary home, 2026+ → **economics-only card, explicitly "no federal credit"** (credit expired 2025, per §9 sunset calendar)
- Rental/STR/business property → depreciation/ITC path, **pro tier**

**December rescue sub-flow:** underpayment detected after Oct 1 → **withholding time-machine calculator** — exact W-4/bonus-withholding dollar target to reach safe harbor (withholding is deemed paid evenly across the year, unlike estimates) → employer payroll-cutoff warning → fallback **Jan 15 estimate**.

**Cadence calendar (each is a scheduled trigger):**
| Date | Action |
|---|---|
| Oct 1 | Projection + purchase-list interview |
| Nov 15 | Withholding/deferral last call |
| Dec 1 | Charitable transfers + conversion/harvest decisions |
| Dec 20 | Last-call checklist: placed-in-service, RMD/QCD, FSA, gifts |
| Jan 15 | Q4 estimate |

Every alert deep-links to a one-tap action or pro-export.

**Output-contract additions per year-end card (schema fields — extend `OptimizationFinding`):**
`deadline` · `lead_time_days` · `net_cash_cost` · `tax_saved` · `cliff_bonus_value` (value from cliff restoration — often EXCEEDS tax_saved) · `reversible: true|false`

**Infra mapping:** full engine is a future milestone (depends on profile graph maturity, paystub plane, prior-return plane for safe harbor). Phase 11 should: (a) add the six output-contract fields to the `OptimizationFinding` schema now (additive migration) so findings are forward-compatible; (b) implement the cliff-proximity constants in `config/tax-rules.php`; (c) encode the anti-waste hard rules (tax_saved + net_cash_cost pairing, no-asserted-need → no card) as Phase 13 rendering guards. Cadence triggers fit `routes/console.php` scheduling. All projections/gap math = `TaxRulesEngineService`, never Claude.

---

## Cross-Cutting Phase Disposition Summary

| Section | v2.1 Phase 11 | Phase 12 | Phase 13 | Future milestone | Owner decision |
|---|---|---|---|---|---|
| §6 Paystub plane | — | doc-intake form types | — | full benefit arbitrage | — |
| §7 Life events | 4 data-detectable triggers | — | — | full engine + annual interview | — |
| §8 Penalty sweeps | transaction-observable subset | — | — | data-plane-dependent sweeps | — |
| §9 Rule schema | ADOPT NOW (all rules) | — | `band` gating | — | — |
| §10 Roadmap | items 1–3 | items 4, 9 | — | items 5–8, 10 | ordering changes |
| §11 Prior-return plane | — | form coverage | — | carryforward tracker | — |
| §12 Irreversible guards | transaction-inferable subset | — | wording + best-effort disclaimer | true interrupts (push) | urgency framing |
| §13 Gambling | merchant detector only | — | — | full module | wellbeing lens |
| §14 Notice-response | — | photo-intake pattern | — | module | letter generation (legal) |
| §15 Liability layer | — | — | disclaimer + terminate-at-human | — | CPA network, SOC 2, §7216 |
| §16 Year-end engine | OptimizationFinding fields + cliff constants | — | anti-waste render guards | full Q4 engine | — |
