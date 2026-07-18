# Shrinkage Ledger — SpendifiAI

## frozen
public/jaz/**          standalone JAZ app — own repo/db/AGENTS.md; never edited from this repo
.env                   live secrets — owner-managed only
.env.*                 env variants (testing/backup) — owner-managed only
database/migrations/** forward-only policy — existing migrations are never edited or removed

## excluded
public/jaz/**          separate project — must not enter the map or any sweep
.planning/**           GSD planning artifacts, not app code
storage/**             runtime/cache artifacts
public/build/**        compiled Vite assets

## red-baselines
tests/Unit/BannedPhraseSystemPromptsTest.php   RED only when run SOLO (needs bannedPhraseList() from BannedPhraseTemplatesTest.php in same process) — always gate with `--filter=BannedPhrase` or full Unit run — 2026-07-18

## keeps
- app/Services/Detectors/*, Scanners/*, Sweeps/* one-method classes (23) — strategy registry, called polymorphically via RedFlagDetectorService::detectorClasses(); NOT C7
- CaptchaService, HardBlockRefusalService — one-method DI seams with dedicated unit suites; Laravel-idiomatic
- normalizeMerchant ×4 (IncomeDetectorService, ReconciliationService, SubscriptionDetectorService, TaxDocumentIntelligenceService) — genuinely diverged per-domain algorithms; any future "normalize better" task must name ONE owner before adding a fifth
- number_format($x, 2, '.', '') ~30 sites in TaxExportService — wire-format contract (TXF/OFX/QBO); never swap to Number:: helpers
- resources/js/utils/formatDate.ts / periodLabels.ts / timezones.ts — built on platform; parseDate T00:00:00 guard is a documented UTC fix
- ProposalConfirmCard ⇄ SuggestedConfirmCard — coincidental twins, divergent contracts, will diverge further
- getSearchQueries Gmail ⇄ Outlook — the divergence IS the provider query syntax; both read config/email-search.php
- handleCallback Gmail ⇄ Outlook OAuth — different SDKs; shared tail not worth a home
- TimelineFilter.tsx formatDate — Date→ISO, different concept from utils/formatDate (name collision only)
- fmtCents ActionCenterWidget ⇄ OptimizationChecklistView — divergent UI contracts per caller
- scopeActive ×6 — semantics differ per model; coincidental name
- spendifiai.sync_digest.enabled — live kill-switch (read SendSyncDigestEmail.php:32); dead-looking on purpose
- plaid.env === 'sandbox' switches + SecurityHeaders env CSP branch + AppServiceProvider local slow-query log — deliberate operational env branches
- Legacy DATA-compat branches (both arms reachable, legacy values in DB): DurableFactsController w4 label map, ira_type/ira_types fallbacks (4 sites), StatementUploadController legacy dismissed key, InterviewOrchestratorService FORMAT_VERSION ladder
- config/tax-detection.php inflation_adjusted / *_always booleans — tuning tables, not flags
- AuthController::me — alive via route string api.php:148 (map x0 is false)
- scripts/generate_partner_rtf.php h1-h4/p helpers — called 100+ times top-level (map doesn't count script-level calls)
- SeoPage scopes/accessors, CookieConsent scopes — alive via Laravel derived names
- RetryFailedEmails::refetchVia* — add try/catch+logging, not pass-throughs
- CancellationLinkFinderService — C7-shaped but service-layer convention earns its keep
- Laravel-skeleton commented config examples (config/auth.php:68-71, mail.php:58-61, database.php:112-113) — upstream stock, not noise
- config/spendifiai.php extraction_thresholds.classification_gate — READ at ExtractTaxDocument.php:111 (siblings are dead; this one is not)
