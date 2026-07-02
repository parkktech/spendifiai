# Feature Capture — Sponsored Placements / Advertising System (owner, 2026-07-02)

**Status: QUEUED — do NOT build until the current v2.1 fix/polish cycle and P13 are done.** Owner: "Another feature I want to add to the Admin area NOT now but put in the Queue for when these are done."

## Owner's description (intent, near-verbatim)
An advertising system managed from the Admin area. Advertisements appear inline in the **Transactions** list and within **recommendations** (and other relevant places). Example: owner's friend **Mike Greenburg, investment advisor at Merrill Lynch** — added as an advertiser in the admin DB; for **users with extra money / larger savings**, his ads show inline as an offer: "click here to consult Mike for the best possible ROI on your extra savings."

## Implied scope (for future planning)
1. **Admin CRUD** for advertisers/campaigns (name, firm, headline, CTA copy, link/contact, creative, active window) — follows the existing CancellationProvider admin pattern; lives in the new bottom-pinned Admin group (Decision 8).
2. **Targeting engine** — deterministic rules against data we already compute: monthly surplus (budget waterfall), savings size, IncomeOptimizationProfile fields (e.g. high headroom, large extra income). Reuse the detector/materiality-gate architecture (config-driven thresholds, versioned rules with expiry — same schema as tax-detection rules).
3. **Placement slots** — inline card in Transactions list (every Nth row / contextual), a slot in Where-to-Cut/recommendations feed, possibly the Optimize report's professional-review section ("talk to a professional" is a natural sponsored slot).
4. **Tracking** — impressions/clicks per campaign for the admin dashboard.

## Compliance & design guardrails (flag at planning time)
- **Sponsored labeling is mandatory** — every placement clearly marked "Sponsored"/"Advertisement"; never blended into educational findings, or it erodes the SAFE educational-only boundary the whole v2.1 milestone is built on.
- Targeting uses users' financial data → privacy/disclosure review needed (privacy policy update; consider an ads-personalization opt-out).
- Investment-advisor promotion: the AD is the advisor's offer, not our advice — copy must never read as SpendifiAI recommending an investment strategy (RIA boundary). "Consult X about your options" framing, not "best possible ROI" as our claim.
- Placement in the professional-review report section may be the HIGHEST-fit slot (users already told to consult professionals) — but requires the clearest labeling.

## Suggested future REQ category: ADS-01.. (new milestone, likely v2.2)
