# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v2.0 — Tax Document Vault & Accountant Portal

**Shipped:** 2026-03-31
**Phases:** 4 | **Plans:** 16 | **Files changed:** 130 | **Lines:** +8,157

### What Was Built
- Secure tax document vault with local/S3 storage, signed URLs, and immutable hash-chain audit trail
- Two-pass AI extraction pipeline for 25 tax form types with per-field confidence scoring and inline review UI
- Accountant portal with firm registration, branded invite links, threaded annotations, and missing document requests
- Cross-document intelligence: missing document detection, anomaly flagging, transaction-to-document linking
- 225 tests (761 assertions), zero build errors

### What Worked
- Wave-based parallel execution — Plans 03/04 and 02/03 executed in parallel, cutting wall-clock time significantly
- Plan checker caught 4 real issues before execution (admin purge gap, UI-04 overclaim, nav link missing, truth framing)
- Verification after execution caught 4 integration bugs between parallel Wave 3 agents — URL mismatches, field name mismatches, category value mismatches
- Gap closure pattern worked cleanly — one targeted plan fixed all 4 integration bugs
- Claude's discretion on Phase 7-9 context decisions (user chose "do it your way") kept momentum high

### What Was Inefficient
- Phase 6 had integration bugs between parallel frontend agents (Wave 3) — backend and frontend weren't synchronized on field names and URL paths, requiring a gap closure cycle
- PostgreSQL RULES vs BEFORE triggers for audit immutability — the original approach conflicted with FK cascades, discovered during build validation (Phase 9)

### Patterns Established
- Service layer in `app/Services/AI/` for all Claude AI interactions (TransactionCategorizerService, TaxDocumentExtractorService, TaxDocumentIntelligenceService)
- Confidence threshold pattern: configurable in `config/spendifiai.php` with green/amber/red UI badges
- Hash-chain audit trail with BEFORE triggers (not RULES) for PostgreSQL immutability
- Signed URLs for all document access — no direct file paths ever exposed
- On-demand intelligence with caching (not background jobs)
- Threaded annotations with depth limit (2 levels)

### Key Lessons
1. Parallel frontend agents need explicit contracts — when Wave 3 has backend + frontend plans, ensure URL paths and field names are explicitly synchronized in plan actions
2. PostgreSQL RULES are too restrictive for tables with FK relationships — BEFORE triggers give finer control
3. "Do it your way" from the user is a signal to move fast with sensible defaults — don't over-deliberate
4. Plan checker verification is high-value — the 4 issues it caught pre-execution would have been harder to fix post-execution

### Cost Observations
- Model mix: 100% Opus for orchestration and execution
- Sonnet used for plan checking and verification (lighter, faster)
- Total v2.0 execution: ~4 phases in single session with auto-advance chain

---

## Cross-Milestone Trends

| Metric | v1.0 | v2.0 |
|--------|------|------|
| Phases | 5 | 4 |
| Plans | 15 | 16 |
| Tests | 142 | 225 |
| Assertions | 524 | 761 |
| Gap closures | 0 | 1 |
| Build validation | N/A | Clean |
