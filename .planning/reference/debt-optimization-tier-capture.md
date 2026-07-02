# Feature Capture — Granular Debt-Optimization Tier (owner, 2026-07-02 — Decision 11)

**Status: QUEUED — builds AFTER the scenarios engine implementation lands.** Owner: "Once they finish, then we can say: do you want to be more granular? Are you prepared to give me interest rates and terms on all your loans? And your credit score? We can then create an optimization plan for your current debt — to strategize balance-transfer opportunities, personal lines of credit, HELOC, etc., to further maximize your current financial portfolio."

## Product shape
1. **Opt-in gate (progressive disclosure)**: after the core scenario flow, offer the granular tier: "Want to go deeper? If you can provide interest rates and terms for your loans — and your credit score — we can analyze your debt structure." Extends the per-objective readiness model with a fourth objective: **Debt**.
2. **Data acquisition (reuse the pattern)**: loan inventory seeded from what we already detect (loan-servicer transactions, HousingDetectionService mortgage, subscriptions/cost-of-living loans) → user fills rates/terms/balances per loan via interview or loan-statement uploads (NEW doc types: loan statement, credit-card statement w/ APR — through the vault pipeline). Credit score = SELF-REPORTED band (user enters; we never pull credit — no bureau integration; store encrypted as a UserTaxFact with provenance).
3. **Deterministic debt engine** (TaxRulesEngineService-style, config-driven, zero Claude math): payoff-order strategies (avalanche vs snowball with total-interest deltas), balance-transfer break-even math (fee % vs interest saved over promo window), consolidation comparisons (personal LOC / HELOC vs current blended APR), minimum-payment vs accelerated schedules, cash-flow interaction with the take-home scenarios (a debt plan changes available surplus → scenarios engine should consume it).
4. **Scenario options + checklist**: Option layouts like the tax scenarios ("Option A: fastest payoff · Option B: lowest monthly · Option C: max cash freed") → chosen option renders Decision-9 checklists with benefit lines ("Transferring card X saves ~$Y in interest over 18 months, net of the 3% fee").
5. **Report**: a Debt section in the optimization report + pro-review routing where structures are complex.

## Liability guardrails (flag at planning)
- **Educational comparisons of product CATEGORIES, never specific lenders/products** (that's the future ads system's clearly-labeled job — natural synergy with the [advertising capture](advertising-system-capture.md), but the boundary between education and sponsored placement must be explicit).
- HELOC/secured-consolidation items MUST carry the "your home becomes collateral" risk warning; balance-transfer items carry promo-expiry + fee caveats; all fact-gated per Decision 9.
- Credit score: self-reported only, encrypted, band-level use ("scores in your range commonly qualify for..."), never a promise of approval.
- Sequencing note: P13 (security/legal hardening) should run before or alongside this tier's launch since it adds highly sensitive data (rates, balances, credit score).

## Fits the existing architecture 1:1
facts store (loan facts + provenance) · interview (granular-tier gap questions) · engine (debt math module) · scenarios (payoff options) · checklist (actions + benefits) · report (debt section) · vault (loan-statement doc types).
