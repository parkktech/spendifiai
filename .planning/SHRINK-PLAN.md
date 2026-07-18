<!-- map-fp: 0071ae6ce68d -->
<!-- est-savings: -1864 -->

# SHRINK-PLAN — SpendifiAI (audit 2026-07-18)

Baseline at audit: full Pest suite GREEN — 1,432 passed, 6,332 assertions, 1 risky
(fixed via TODO-1 below). Seven sweeps ran (dead-symbol, duplication, structure,
flag, platform, noise, suite-health) via parallel evidence-only auditors.

> **Tiering: suite-gated** (no coverage artifact in this repo). T0/T1 rows
> execute only when the `coverage` column names an observing suite that runs
> green before+after; rows with no nameable suite stay T2. (safety-model §4)

**Standing execution rules for every row:**
- Tests run SERIALLY — one `php artisan test` process at a time (shared `ledgeriq_test` DB; concurrent runs cascade-fail).
- `tests/Unit/BannedPhraseSystemPromptsTest.php` is only valid run together with `BannedPhraseTemplatesTest.php` (`--filter=BannedPhrase`) — red solo by design.
- Frontend rows gate on `npm run build` (tsc + vite) — no JS unit runner exists.
- Owner context: this run is `/srk:shave --full-send` explicitly elected by the owner, which serves as the CLAUDE.md rule-8/10 sign-off for the removals listed here; every commit is atomic and revertible, nothing is pushed.

| # | candidate | file:line | catalog | tier | est. net LOC | effort | confidence | coverage | evidence |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Delete pre-split zombie backup `SpendWiseController.php.bak` (939 lines) | app/Http/Controllers/Api/SpendWiseController.php.bak | C10 | T0 | -939 | S | high | gate: full Pest suite + `php artisan route:list` | grep 0 hits repo-wide; `.bak` not autoloadable (PSR-4); pre-split snapshot, split commit preserves history; confirmed by 2 independent sweeps |
| 2 | Remove commented `verification.verify` route block + dead `VerifyEmailController` import | routes/auth.php:43-45 + line 11 | C10 | T0 | -4 | S | high | tests/Feature/Auth/EmailVerificationTest.php | live replacement routes/web.php:101-132; KEEP why-note line 42 |
| 3 | Remove commented email-verify API route (keep note lines 75-76) | routes/api.php:77-78 | C10 | T0 | -2 | S | high | tests/Feature/Auth/EmailVerificationTest.php | live route web.php:132; `EmailVerificationController` import stays (used :154) |
| 4 | Remove no-op legacy cache-key `Cache::forget` | app/Http/Controllers/Api/DashboardController.php:1111-1112 | C4 | T0 | -3 | S | high | tests/Feature/Dashboard/DashboardFinancialBlocksTest.php | un-suffixed key written nowhere; 60s TTL expired legacy entries at deploy of 57f5a00 |
| 5 | Remove dead `NarrationService::callClaude()` (D19 leftovers) | app/Services/NarrationService.php:356-406 | C4/C6 | T1 | -50 | S | high | tests/Feature/NarrationServiceTest.php | zero callers (8 `->callClaude(` hits are other services' own copies); no subclasses; "test harness" docblock claim stale (grep tests/ = 0); confirmed by 2 sweeps |
| 6 | Remove dead `OptimizationReportNarratorService::callClaude()` | app/Services/OptimizationReportNarratorService.php:~433-500 | C4/C6 | T1 | -53 | S | high | tests/Feature/OptimizationReportGeneratorTest.php + tests/Unit/Safe03ConsolidationTest.php + `--filter=BannedPhrase` pair | same evidence shape as #5; only `callClaudeStructuredSection` (:222, x6) live |
| 7 | Remove dead legacy accessor `narrateSectionProse()` | app/Services/OptimizationReportNarratorService.php:150-161 | C4/C6 | T1 | -12 | S | med | same gates as #6 + tests/Feature/Scenarios/ScenarioControllerTest.php | D19 rollout complete (v2.1 shipped 2026-07-03); grep 1 hit = def |
| 8 | Hoist `checkAndIncrementBudget()` 4→1 into trait `app/Services/Concerns/GuardsClaudeBudget` | app/Services/AI/TransactionCategorizerService.php:547, app/Services/InterviewOrchestratorService.php:1838, app/Services/NarrationService.php:229, app/Services/OptimizationReportNarratorService.php:334 | C9 | T1 | -60 | S | high | tests/Unit/ClaudeBudgetTest.php + tests/Feature/NarrationServiceTest.php + tests/Feature/InterviewOrchestratorServiceTest.php + tests/Unit/Services/TransactionCategorizerServiceTest.php | 4 bodies byte-identical (only visibility/docblock differ); cache-key format is a read contract (Admin/AiUsageController.php:47) — trait preserves it; ClaudeBudgetTest uses ReflectionMethod (trait-safe) |
| 9 | Hoist `cleanHtml()` 3→1 into trait `app/Services/Email/Concerns/CleansEmailHtml` — method stays PUBLIC | app/Services/Email/GmailService.php:288, ImapEmailService.php:362, MicrosoftOutlookService.php:290 | C9 | T1 | -35 | S | high | tests/Feature/ProcessOrderEmailsImapTest.php + tests/Feature/Email/EmailConnectionTest.php | 3 bodies byte-identical; external callers TransactionGuidedSearchService.php:591,708,812 require public visibility |
| 10 | Remove dead `EmailParserService::recategorizeItems()` | app/Services/AI/EmailParserService.php:206 | C6 | T1 | -36 | S | high | tests/Feature/ProcessOrderEmailsImapTest.php + tests/Unit/InjectionPenTest.php | never wired since copy-in commit ~4-5mo; grep 1 hit = def |
| 11 | Delete superseded FormRequest `RespondToSavingsActionRequest` (whole file) | app/Http/Requests/RespondToSavingsActionRequest.php | C6 | T1 | -32 | S | high | tests/Feature/SavingsResponseTest.php | controller type-hints `RespondToPlanActionRequest` instead; FormRequests resolve only via type-hint; 5mo untouched |
| 12 | Remove dead `HsaShoeboxService::totalForYear()` (also deletes a manual-decrypt convention violation) | app/Services/HsaShoeboxService.php:127 | C6 | T1 | -19 | S | high | tests/Feature/HsaShoeboxTest.php | never called; body uses `decrypt(getRawOriginal(...))` against project encrypted-casts rule |
| 13 | Remove dead `BankStatementParserService::parseFile()` | app/Services/BankStatementParserService.php:11 | C6 | T1 | -18 | S | high | tests/Unit/InjectionPenTest.php + tests/Feature/StatementUploadTest.php | jobs call parsePdf/parseCsv directly; wrapper bypassed; grep 1 hit = def |
| 14 | Remove ~14 unread config keys from `config/spendifiai.php` (keep `extraction_thresholds.classification_gate`) + update CLAUDE.md CONFIGURATION section in same commit | config/spendifiai.php:13-14,20-25,28-29,63-66,117-118 | C4 | T1 | -17 | S | high | full Pest suite (config boots every test) + `php artisan config:cache && php artisan config:clear` | zero reads; superseded by class consts (TransactionCategorizerService:21-25), hardcoded schedule (routes/console.php), hardcoded export dir (TaxExportService.php:44) |
| 15 | Remove dead static `ReportStalenessPolicy::classifyTrigger()` | app/Services/ReportStalenessPolicy.php:59 | C6 | T1 | -10 | S | high | tests/Feature/ReportStalenessTest.php | class heavily alive; only this method dead |
| 16 | Remove dead private `ScenarioController::extractKnobList()` | app/Http/Controllers/Api/ScenarioController.php:719 | C6 | T1 | -8 | S | high | tests/Feature/Scenarios/ScenarioControllerTest.php + ScenarioChooseTest.php | private; grep 1 hit = def |
| 17 | Remove dead accessor `HardBlockRefusalException::getRefusal()` | app/Exceptions/HardBlockRefusalException.php:55 | C6 | T1 | -6 | S | high | tests/Feature/HardBlockRefusalTest.php + tests/Unit/HardBlockRefusalServiceTest.php | render() uses property directly; dead since birth |
| 18 | Inline pass-through `ProcessOrderEmails::fetchViaImap()` into its match arm | app/Jobs/ProcessOrderEmails.php:236 (caller :60) | C2 | T1 | -6 | S | high | tests/Feature/ProcessOrderEmailsImapTest.php | pure same-arg delegation; siblings have real logic and stay |
| 19 | Delete orphaned `AiOnboardingUploadSection.tsx` + 2 stale doc-comment references | resources/js/Components/SpendifiAI/AiOnboardingUploadSection.tsx + resources/js/Pages/UserProfile/Index.tsx:9,22 | C6 | T2 | -166 | M | med | gate: `npm run build` + full Pest suite | deliberately unwired in one-journey consolidation (3b733ef, 2wk); only remaining refs are comments |
| 20 | Delete orphaned Inertia page `VerifyEmailCallback.tsx` + orphaned `VerifyEmailController.php` (orphan completes after #2) | resources/js/Pages/Auth/VerifyEmailCallback.tsx + app/Http/Controllers/Auth/VerifyEmailController.php | C6 | T2 | -70 | S | med | gate: `npm run build` + tests/Feature/Auth/EmailVerificationTest.php | zero renders of the page string; controller's only ref is the commented import removed in #2; also removes latent token-prefix console.log |
| 21 | Delete Breeze leftovers `NavLink.tsx` + `ResponsiveNavLink.tsx` | resources/js/Components/NavLink.tsx, ResponsiveNavLink.tsx | C6 | T2 | -44 | S | high | gate: `npm run build` | zero imports; scaffold-era, 5mo untouched |
| 22 | Delete orphaned `Marketing/FeatureCard.tsx` + `Marketing/StatsCounter.tsx` | resources/js/Components/Marketing/ | C6 | T2 | -42 | S | high | gate: `npm run build` | zero import sites for `Marketing/` |
| 23 | Remove dead `ReconciliationService::getTaxSummary()` | app/Services/ReconciliationService.php:311 | C6 | T2 | -40 | S | high | gate: full Pest suite | never wired since copy-in; duplicates TaxController territory; no observing suite → T2 |
| 24 | Remove never-wired `Enforce2FA` middleware + `'2fa'` alias + CLAUDE.md middleware-table row | app/Http/Middleware/Enforce2FA.php + app/Providers/AppServiceProvider.php:98,:5 | C6 | T2 | -37 | S | high | gate: full Pest suite + `php artisan route:list` | alias applied to zero routes; `session('2fa_verified')` set nowhere (would 403 all users if wired); real 2FA enforcement in AuthController:80-90 (ApiAuthTest:84) |
| 25 | Remove 3 dead TS interfaces (`AlternativeSuggestion`, `ConsentPreferences`, `ConsentConfig`) | resources/js/types/spendifiai.d.ts:873,1064,1072 | C6 | T2 | -25 | S | high | gate: `npm run build` | defs only; in-repo d.ts, not published surface |
| 26 | Hoist `getCookie` 4→1 into new `resources/js/utils/cookies.ts` | resources/js/app.tsx:15, bootstrap.ts:9, contexts/ConsentContext.tsx:50, contexts/ImpersonationContext.tsx:21 | C9 | T2 | -14 | S | high | gate: `npm run build` | 4 byte-identical copies; bootstrap.ts/app.tsx are auth-boot load-order-critical — verify no import cycle before commit |
| 27 | Remove dead static `UserFinancialOverride::getOverridesFor()` | app/Models/UserFinancialOverride.php:27 | C6 | T2 | -11 | S | high | gate: full Pest suite | callers use the model directly (DashboardController:268,1087) |
| 28 | Remove unreachable `recommendation` branch in `handleLegacyApply` | resources/js/Pages/Dashboard.tsx:965-979 | C4 | T2 | -10 | S | high | gate: `npm run build` | sole caller gates on `type === 'overspending'`; `/apply` endpoint unaffected (Savings/Index.tsx:47 still calls it) |
| 29 | Swap raw `fetch` → `useApiPost` in Connect OAuth handler | resources/js/Pages/Connect/Index.tsx:295-303 | C5 | T2 | -6 | S | med | gate: `npm run build` | duplicate of bootstrap axios auth layer |
| 30 | Hoist byte-identical `formatCurrency` k-format 2→1 (chart-local shared helper; do NOT platform-swap — Intl compact renders differently) | resources/js/Components/SpendifiAI/SpendingChart.tsx:25-28 ⇄ SavingsTrackingChart.tsx:18-21 | C9 | T2 | -4 | S | high | gate: `npm run build` | byte-identical; same domain |
| 31 | Remove dead duplicate Plaid cred keys (`spendifiai.plaid.client_id/secret/env`; base_url/products/country_codes/webhook_url stay) | config/spendifiai.php:44-46 | C4 | T2 | -3 | S | med | gate: full Pest suite (PlaidFlowTest, PlaidStatementsTest) | every live read uses `services.plaid.*` |
| 32 | Swap `Cache::has`+`Cache::put` → atomic `Cache::add` | app/Http/Middleware/TrackUserActivity.php:19-22 | C5 | T2 | -2 | S | high | gate: full Pest suite | behavior-equivalent, removes has/put race |
| 33 | Introduce `app/Traits/BelongsToUser` (user() + scopeForUser) and apply to the 10 models with byte-identical `scopeForUser` + bare `user()` relations — EXCLUDE custom-FK models (AccountantActivityLog, Household, DocumentRequest, HouseholdInvitation, AccountantClient, CookieConsent, UserFinancialProfile, DocumentAnnotation) | 10 models incl. UserTaxFact:102, TaxDocument:77, OptimizationFinding:110 | C1 | T2 | -80 | M | high | gate: full Pest suite (optimizer suites use `forUser()` heavily) | 10 byte-identical scopes, docblocks cross-reference each other; precedent: app/Traits/BelongsToHousehold.php |

## Bugs found (not shaves — fix-first, separate labeled commits)

- **[B1] aiTypeMap drift** — app/Services/AI/TaxDocumentIntelligenceService.php:40-52 missing 4 mappings present in app/Services/IncomeDetectorService.php:42-58 (`Income (Salary)→employment`, `(Freelance)→contractor`, `(1099)→contractor`, `(Investment)→interest`). False "missing W-2/1099" flags + missed doc↔txn links. IncomeDetector copy is authoritative. Blocks Deferred-D2.
- **[B2] formatDate UTC off-by-one** — DocumentCard.tsx:62, DocumentRequestCard.tsx:10, DocumentUploadFlow.tsx:86, Pages/Accountant/Clients.tsx:232 lack the `T00:00:00` guard that resources/js/utils/formatDate.ts has → date-only values render the previous day for US users. Fix = import the shared util (also collapses 4 dup defs).
- **[B3] Dead error handling on raw fetch** — resources/js/Components/SpendifiAI/HouseholdSection.tsx:59,75,91: `fetch` doesn't reject on 4xx/5xx → failed member-remove still shows success toast. Fix = swap to `useApiPost` (already imported in the file).
- **[B4] Missing auth header** — resources/js/Pages/Accountant/Dashboard.tsx:201 invite-link fetch sends no Authorization header (cookie-path only, known-fragile area). Fix = axios/useApi.
- **[B5] Dashboard cache invalidation silent no-op** — OrderItemController.php:26-28, SavingsController.php:68-70,139-141,460-462, SubscriptionController.php:138-140, TransactionController.php:124-126 forget a legacy key format that is never written; CLAUDE.md's invalidation promise doesn't hold (staleness bounded only by 60s TTL). Fix = forget the real default key format.
- **[B6] Host-only cookie vs owner bare-domain mandate** — resources/js/contexts/ImpersonationContext.tsx:29-34 sets cookies without `domain` attr; owner rule: ALL cookies bare domain.
- **[B7] Risky (0-assertion) test** — tests/Feature/SweepsAndScannersTest.php:168: all expects inside `if ($finding)` and `$finding` is null → gate observes nothing. 7 siblings share the fragile pattern (lines 81,110,200,230,264,302,354) but currently match rows.
- **[B8] Hollow Plaid webhook coverage** — tests/Feature/Plaid/PlaidStatementsTest.php:95 asserts only `method_exists`; no test posts to `/api/v1/webhooks/plaid` (CLAUDE.md's webhook-test claim is stale). Test-debt note.
- **[B9] Slow suites hit the real Anthropic API** — EnhancedProfileTest (29s), PaystubProposalFlowTest (16s), PayFrequencyDerivationTest (8s), BonusStructureTest (7s): no `Http::fake`/`preventStrayRequests`; sync queue runs categorizer inline with sleep(2) retries. Likely burns real tokens every test run. Owner decision on faking strategy.

## TODO before shaving

- [ ] **[bug]** B7 risky test observes nothing — a named gate that can't fail.
      → Add `expect($finding)->not->toBeNull()` before the framing expects in SweepsAndScannersTest.php:168; one `fix:` commit.

- [ ] **[bug]** B1 aiTypeMap drift causes false missing-document flags.
      → Backport the 4 mappings to TaxDocumentIntelligenceService; `fix:` commit gated on TaxDocumentIntelligenceTest.

- [ ] **[bug]** B2 previous-day dates in 4 document/accountant surfaces.
      → Replace the 4 local formatDate copies with `utils/formatDate` import; `fix:` commit gated on `npm run build`.

- [ ] **[bug]** B3 failed household actions show success toasts.
      → Swap 3 raw fetches in HouseholdSection.tsx to `useApiPost`; `fix:` commit.

- [ ] **[bug]** B4 invite-link copy breaks if the cookie path regresses.
      → Use axios (global Bearer) in Accountant/Dashboard.tsx:201; `fix:` commit.

- [ ] **[bug]** B5 documented cache invalidation doesn't happen.
      → Point the 4 controllers' forget at the real `dashboard:{id}:all::` key (mirror DashboardController::clearDashboardCache); `fix:` commit gated on full suite.

- [ ] **[bug]** B6 impersonation cookie violates the bare-domain rule.
      → Set/clear with the bare-domain pattern from bootstrap.ts; `fix:` commit.

- [ ] **[security]** `.env.backup-predebugfix` sits in the repo root (verified NOT web-reachable — vhost docroot is `public/`; defense-in-depth only).
      → OWNER: move it outside the project tree or delete it.

- [ ] **[test-health]** B9 test runs likely spend real Anthropic tokens.
      → OWNER: decide faking strategy (Http::preventStrayRequests would currently redden 4 suites).

## Deferred (T2/T3 — each with its unblock condition)

| candidate | est LOC | why deferred | unblock |
|---|---|---|---|
| D2: `IncomeTypeClassifier` neutral home (IncomeDetectorService ⇄ TaxDocumentIntelligenceService cluster, 8→4 defs) | -90 | blocked by B1 (fix first) + normalizeMerchant SALARY-strip divergence needs adjudication (which behavior is right?) | owner adjudicates ⚖ SALARY-strip |
| D4: `AnthropicClient` consolidation (20 call sites / 13 services → 1 client) | -120 | L-effort refactor, several target services have NO observing suite; feature-scale, not a shave | route as a GSD phase; #5/#6 already shrink its scope by 115 LOC |
| D8: TaxExportService `aggregate*` ladder → table | -30 | thin gate (TaxExportServiceTest only, indirect) + wire-format blast radius (TXF/QBO consumed by TurboTax/QuickBooks) | characterization tests for aggregate outputs |
| D9: Admin Create⇄Edit form dedupe (Charities, Providers) | -120 | zero observing gate (no admin e2e); JSX refactor unverifiable here | admin e2e spec, or owner accepts tsc-only gate |
| `PlaidService::getBalances()` dead method | -24 | v2.1 branch actively churns Plaid balance code | after feature branch merges, re-verify + remove |
| `FirmInviteMail` + blade | -85 | v2.1 roadmap doc references a planned firm-invite feature | owner ⚖: still planned? |
| `TWO_FACTOR_ENABLED` + digits/period/algorithm config keys | -4 | advertised kill-switch that gates nothing — remove keys OR honor the flag (feature decision) | owner ⚖ |
| `plaid:backfill --sync` legacy mode | -18 | documented operational CLI surface; owner may use it | owner ⚖ |
| DurableFactsController manual authz → policy | -6 | policy allows household partners, manual check denies — behavior adjudication (which is intended?) | owner ⚖ |
| Parked verified-dead small fry: `User::syncTier` (phase-14 docs mention it), `Transaction::scopeDonation`/`getIsDonationAttribute`, relations `User::plaidStatements`/`BankConnection::plaidStatements`/`TaxDocument::auditLogs`/`Order::matchedTransaction`, `PdfSplitterService::splitByRanges/getPageCount`, `OptimizationReportView.tsx::isSectionCollapsible`, 7 one-off artisan backfill commands | ~-80 | individually tiny; several touch possible-future surface | batch re-verify next audit |

## Hidden dependencies discovered

(appended by shave runs; reverted attempts land here)

- Suite-coupling: `BannedPhraseSystemPromptsTest` ← `bannedPhraseList()` defined in `BannedPhraseTemplatesTest` — the pair is one atomic gate unit; renaming/deleting the Templates file silently breaks SAFE-01 enforcement.
