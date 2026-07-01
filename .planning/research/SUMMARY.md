# Research Summary: SpendifiAI v2.1 "Optimize My Income"

**Project:** SpendifiAI v2.1 Milestone — Optimize My Income (Tax/Income Optimization + Smart Financial Interview)
**Domain:** Personal tax optimization + financial education — subsequent milestone built on live Laravel 12 + React 19 stack
**Researched:** 2026-07-01
**Confidence:** HIGH (stack and architecture verified against codebase; features cross-referenced with fintech UX research; pitfalls sourced from regulatory guidance and documented fintech failures)

---

## Executive Summary

**Optimize My Income** is an educational tax-optimization and smart-interview feature for SpendifiAI's existing personal-finance app. Experts build this by: **(1) never letting Claude compute tax dollars** — all IRS math lives in a deterministic `TaxRulesEngineService` read from a year-versioned config file, **(2) using Claude only to generate plain-English explanations of pre-computed findings**, and **(3) maintaining an absolute educational-only liability boundary** via consistent modal framing ("may," "could," "consider"), persistent disclaimers, and explicit non-assertion of financial decisions (e.g., "file as Head of Household"). The recommended approach is zero new packages, fully additive architecture (6 new services, 5 new models, no existing code rewrites), and a dependency-ordered 5-phase build: (1) rules engine + data assembly, (2) red-flag detection + cross-source review, (3) interview state machine, (4) report generation, (5) polish + new document types.

The research converges on a critical risk: Claude will hallucinate IRS limits (contribution limits, standard deduction amounts, tax brackets) if asked directly. IRS figures change annually via inflation adjustments. The entire feature rests on a single-source-of-truth `config/tax-rules.php` keyed by tax year. Every math operation reads from this file, never from Claude. This constraint is load-bearing: a mistake in tax math gives users incorrect guidance, creates liability, and may expose the platform to regulatory scrutiny. All 12 critical pitfalls converge on the same theme: **deterministic, auditable, divorced-from-Claude boundaries at every layer**.

**Immediate action:** Before any planning starts, confirm the constants file structure and rules engine pattern with the tech lead. The entire roadmap depends on this foundation being locked down correctly.

---

## Key Findings

### Recommended Stack

**Zero new Composer packages. Zero new npm packages.** All three pillars (rules engine, document extraction, interview/report) are implemented as new PHP service classes and config data using the existing Laravel 12, Inertia 2, and Claude Sonnet API stack.

**Core technologies (new additions only):**

- **`config/tax-rules.php`** — Versioned tax constants (year-keyed) storing 2026 IRS brackets, standard deductions, contribution limits, SE tax rates, Roth phase-outs, HSA limits. Replaces all hardcoded dollar figures. Updated each November.
  
- **`TaxRulesEngineService`** — Pure PHP deterministic calculation. Reads config, computes: effective/marginal tax rates, standard vs itemized comparison, remaining 401(k)/IRA/HSA room, Roth eligibility, SE tax, QBI deduction. **Zero Claude calls.** Single source of truth.

- **6 new services** — IncomeOptimizerDataAssemblerService (snapshot), RedFlagDetectorService (patterns), CrossSourceReviewService (discrepancies), InterviewOrchestratorService (state machine), OptimizationReportGeneratorService (narratives).

- **5 new models** — IncomeOptimizationProfile (cache), OptimizationFinding (findings), InterviewSession (state), OptimizationQuestion (questions), OptimizationReport (report).

- **New document types** — Extend existing TaxDocumentExtractorService with CheckStub, OfferLetter, RetirementStatement, BenefitsStatement, StockStatement, InsuranceStatement. Reuse two-pass classify→extract. Images via Claude vision (base64, no new library).

- **Existing infrastructure reused** — AIQuestion model + feed (new QuestionType::Optimization case, additive), UserFinancialProfile, IncomeDetectorService, TransactionCategorizerService, TaxDocumentExtractorService, DashboardCacheService.

**Why no packages:** US tax calculation is 7-level bracket iteration + conditionals. Third-party "rules engines" (Ruler, RulerZ) are designed for dynamic rule injection — mismatch for static annual constants. Ruler is abandoned. Plain PHP 8.3 is faster, cheaper, cleaner.

---

### Expected Features

**Must have (v2.1 launch):**
- Document intake for 4 new types (pay stub, offer letter, 401(k), benefits summary)
- Cross-Source Context Engine (assemble facts before asking)
- Guided one-question-at-a-time interview with skip/back
- Filing status red-flag detection (surface mismatch, not assertion)
- Tax withholding check (gap >$500 flags)
- Standard vs itemized comparison
- 401(k) employer match gap calculator
- Traditional vs Roth educational recommendation (deterministic logic, Claude narrates)
- QBI deduction eligibility surface
- Deduction probe questions (home office, vehicle, electronics, pet, meals — prerequisites required)
- Optimization report with disclaimers (persistent, not dismissable globally)
- Ongoing red-flag questions in AI feed (bridges into existing infrastructure)

**Should have (post-validation):**
- Cross-source income anomaly (W-2 vs deposits)
- Offer letter benefit gap analysis
- Deductible subscription detection

**Explicitly out of scope (anti-features):**
- Asserting filing status ("you should file jointly")
- Computing actual refund amount (would be tax prep)
- Investment allocation advice (requires RIA registration)
- Guaranteeing dollar savings (use ranges + uncertainty)
- Auto-filing or tax return generation (PTIN required)
- Gray-area deduction assertions ("your dog IS deductible")
- State tax optimization (federal only for v2.1)
- Global "dismiss all disclaimers" toggle

---

### Architecture Approach

**Deterministic math (PHP) upstream; Claude (narratives) downstream.** Five services, five models, additive only.

**Major components:**
1. **TaxRulesEngineService** — Pure PHP. All IRS figures from config. Never calls Claude. Single source of truth.
2. **RedFlagDetectorService** — 9 named detectors (all deterministic Boolean logic). Claude called only for descriptions of flags (bounded: 3-8 findings typical).
3. **InterviewOrchestratorService** — State machine. Claude for question wording only (~1 call per question, 5-12 typical).
4. **OptimizationReportGeneratorService** — Assembles 4-section report. TaxRulesEngineService computes numbers. Claude for narratives (5 calls max per report).
5. **IncomeOptimizerDataAssemblerService** — Cache-like snapshot from existing encrypted records. No Claude.
6. **CrossSourceReviewService** — Compares documents vs bank vs email. Deterministic logic; Claude for explanations only.

**New models:** IncomeOptimizationProfile (snapshot), OptimizationFinding (findings), InterviewSession (state), OptimizationQuestion (questions), OptimizationReport (report).

**Total Claude calls per cycle:** ~18 (5 for descriptions, 8 for interview, 5 for report). Bounded.

**Integration (minimal, backward-compatible):**
- Add QuestionType::Optimization enum case
- Add UpdateOptimizationFromAnswer listener to UserAnsweredQuestion event
- Guard UpdateTransactionCategory listener to skip optimization questions
- DashboardCacheService invalidation on document extraction

---

### Critical Pitfalls (Top 5)

1. **Claude hallucinating IRS limits** → All figures from `config/tax-rules.php`, never from Claude. Inject constants into Claude's system prompt for explanations only. Test asserts exact match to config.

2. **Crossing educational/advice line** → Enforce modal framing ("may," "could," "consider"). Ban "you should," "you must," "you qualify." Every card shows inline disclaimer. Hard-code framing into system prompts before user-facing work.

3. **Prompt injection via documents** → Never include raw extracted text in system prompt. Use `<document_content>` delimiters. Require structured JSON output schema. Validate against schema. Strip PDF metadata.

4. **Aggressive/incorrect deductions trigger audits** → Every probe gates on prerequisites verified from real data. Never suggest deductions requiring uncertain preconditions. Maintain hard block list (guard dog, mixed-use vehicle, home entertainment). Flag audit-high-risk categories.

5. **Filing status misdetection** → NEVER determine or recommend filing status. Read only stated filing status from UserFinancialProfile. If null, block optimization. Interview asks what the user expects; takes answer at face value. Surface mismatches educationally only.

**Full 12-pitfall list:** See PITFALLS.md. Includes: PII exposure, pro-rata Roth trap, state tax scope creep, breaking AI feed, cache invalidation, migration safety, interview fatigue.

---

## Implications for Roadmap

**5-phase dependency-ordered build:**

### Phase 1: Foundation — Rules Engine + Data Assembly

**Rationale:** Everything depends on this. Tax constants locked before Claude integration. Rules engine deterministic and tested in isolation.

**Delivers:**
- config/tax-rules.php with 2026 IRS brackets, deductions, limits (verified vs IRS.gov)
- TaxRulesEngineService (pure PHP, zero Claude)
- IncomeOptimizationProfile model + migration
- IncomeOptimizerDataAssemblerService
- BuildIncomeOptimizationProfile job (data assembly only)
- Pest tests for rules engine (math correct at boundaries, headroom matches limits exactly)

**Addresses:** Standard vs itemized, 401(k)/IRA/HSA headroom, Traditional vs Roth logic, QBI thresholds, SE deduction

**Avoids:** Claude hallucinating limits, filing status misdetection, aggressive deductions

**Gate:** Profile builds correctly; all math tested; no existing tests broken

---

### Phase 2: Detection — Red Flags + Cross-Source + Interview Machine

**Rationale:** Foundation exists. Detectors run cheaply. Interview state machine transforms findings into questions.

**Delivers:**
- RedFlagDetectorService (9 detectors, all deterministic)
- CrossSourceReviewService (W-2 vs deposits, deductible subscriptions)
- OptimizationFinding model + enums
- InterviewSession + InterviewSessionStatus enum
- OptimizationQuestion + OptimizationQuestionType enum
- InterviewOrchestratorService (state machine, Claude for wording)
- IncomeOptimizerController + endpoints
- QuestionType::Optimization enum case
- SurfaceHighPriorityRedFlags listener (creates AIQuestion for red-flag findings)
- UpdateOptimizationFromAnswer listener (bridge to existing event)
- Guard logic on UpdateTransactionCategory listener
- Frontend: OptimizeIncome page Step 1 (findings list), Step 2 skeleton (interview)
- Pest tests: detectors, state machine, guards, no regressions

**Addresses:** Filing status flag, retirement gap, Traditional vs Roth probe, deduction probes, ongoing red-flag questions

**Avoids:** Aggressive deductions, pro-rata Roth trap, breaking AI feed

**Gate:** Complete interview flow works; findings surface in AI feed; existing categorization tests pass

---

### Phase 3: Report Generation

**Rationale:** Completed sessions exist. Report runs as background job.

**Delivers:**
- OptimizationReport model + OptimizationReportStatus enum
- OptimizationReportGeneratorService (TaxRulesEngineService for numbers, Claude for narratives only)
- GenerateOptimizationReport job (background, triggered by event)
- OptimizationReportReady event + listener
- Controller: GET /optimize/report/{year}
- Stale logic (mark stale on document upload or sync)
- Frontend: OptimizeIncome Step 3 (4-section report, collapsible, persistent disclaimers)
- Blade template for PDF export
- Pest tests: structure correct, disclaimers render, numbers from TaxRulesEngineService, existing tests pass, stale mechanism works

**Addresses:** Optimization report with ranked items, disclaimers, actions, export

**Avoids:** Crossing advice line, state tax scope creep, cache invalidation

**Gate:** Report generates with correct TaxRulesEngineService numbers; Claude narratives present; 225+ existing tests pass; cache works

---

### Phase 4: Polish + New Document Types

**Rationale:** Core complete. Extend to new doc types. Add navigation.

**Delivers:**
- New TaxDocumentCategory enum cases
- Extraction prompt configs for each type
- IncomeOptimizerDataAssemblerService extended for new types
- "Optimize My Income" nav item with badge
- Scheduled tasks: AbandonStaleInterviewSessions, RefreshStaleOptimizationProfiles
- End-to-end test: doc upload → profile rebuild → report stale → refresh → update
- Frontend polish: progress bar, tooltips, last-analyzed timestamp, re-answer capability

**Addresses:** New document intake, ongoing freshness, interview edit

**Avoids:** PII exposure, migration lock safety

**Gate:** New docs extract correctly; report updates; no performance regression

---

### Phase 5: Validation + Hardening

**Rationale:** Feature complete. Audit, test, harden.

**Delivers:**
- Security audit: prompt injection penetration test, SSN masking verification, error tracking scrubbing
- Legal review: framing check, no assertions, disclaimers everywhere
- User testing: interview fatigue (5-question cap), pre-population, mobile experience
- Documentation: support onboarding, educational vs advice boundary, dispute handling
- Monitoring: finding accuracy, completion rate, clarity feedback

**Addresses:** Production-ready feature

---

### Phase Ordering Rationale

1. **Rules Engine first:** Everything depends on correct tax math.
2. **Detection next:** Builds on foundation. Detectors cheap; interview state belongs here.
3. **Report generation:** Requires completed sessions. Assembly is simple once findings/answers exist.
4. **Polish:** New doc types extend Phase 2 logic. Navigation late. Maintenance tasks operational.
5. **Validation:** Security/legal review on complete feature, not in isolation.

---

### Research Flags

**Phases needing deeper research during planning:**
- **Phase 2 (Detectors):** Finalize each detector's prerequisite gates via legal review before coding.
- **Phase 2 (Interview Fatigue):** User test to validate 5-question initial cap drives engagement.
- **Phase 3 (Report Clarity):** Get 2-3 accountants to review section structure and ordering.

**Phases with standard patterns (skip research):**
- **Phase 1 (Rules Engine):** Config-driven constants and bracket math are well-established.
- **Phase 4 (Document Extraction):** Two-pass classify→extract already exists in v2.0; extending is mechanical.
- **Phase 5 (Hardening):** Security patterns documented; no research needed, only execution.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | **HIGH** | All tech verified against codebase. Zero new packages confirmed. Design straightforward. |
| Features | **HIGH** | Cross-referenced against Keeper Tax, TurboTax, fintech research. Dependencies clear. Anti-features well-defined. |
| Architecture | **HIGH** | Direct codebase inspection confirms existing models, services, enums, jobs, events. New components follow house patterns. |
| Pitfalls | **HIGH** | Sourced from IRS OPR guidance, Bloomberg Tax, Snyk, fintech case studies, project safety rules. Each has documented real-world failures. |

**Overall confidence: HIGH**

### Gaps to Address

1. **Exact deduction probe prerequisites:** Research identifies examples but not specific transaction patterns/profile flags per probe. Recommend: Phase 1 planning should define gate for each of 5 initial probes.

2. **Interview question templates:** Research specifies boundary but not exact phrasing. Recommend: Phase 2 planning should draft 5 templates; tax professional review.

3. **Report section priority:** Research suggests 4 sections but not ordering/emphasis. Recommend: Phase 3 planning should consult accountants on actionability.

4. **State tax education scope:** Research limits to federal but not how to educate on state limitations (e.g., California TCJA non-conformity). Recommend: Phase 5 planning draft state-specific disclaimers.

---

## Sources

### Primary (HIGH confidence)

- **IRS Rev. Proc. 2025-32** — 2026 brackets, deductions, LTCG thresholds
- **IRS Notice 2025-67** — 2026 retirement limits
- **IRS Notice 2026-05** — 2026 HSA limits
- **Anthropic Claude Vision Docs** — Image extraction best practices
- **SSA.gov / IRS Topic 751** — 2026 FICA, wage base

### Secondary (MEDIUM confidence)

- **Bloomberg Tax — AI Hallucinations in Tax**
- **Snyk — Prompt Injection via Invisible PDF Text**
- **Journal of Accountancy — AI & Circular 230 Standards**
- **Keeper Tax, TurboTax, Instead.com UX research**

### Tertiary (PROJECT docs)

- **CLAUDE.md** — Safety rules, tech stack, patterns
- **PROJECT.md** — v2.1 scope, constraints
- **Codebase inspection (2026-07-01)** — Models, Services, Enums, Jobs, Events

---

*Research completed: 2026-07-01*
*Confidence: HIGH*
*Ready for requirements definition: yes*

**Next step:** Roadmapper uses this summary + phases to structure ROADMAP.md. Phase 1 planning: (1) finalize config/tax-rules.php structure, (2) lock TaxRulesEngineService API contracts, (3) define deduction probe prerequisites, (4) schedule security/legal review for Phase 5.
