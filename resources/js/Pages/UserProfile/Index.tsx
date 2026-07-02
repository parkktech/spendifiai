/**
 * UserProfile/Index — Phase 12-05 Task 3 (D5, UI-03).
 *
 * The User/Family Profile page (owner Decision 5, option (a)).
 * Composes existing and new sections into one page:
 *
 *   1. EnhancedProfileSection    (imported UNCHANGED from Phase 11)
 *   2. LearnedTaxFactsSection    (imported UNCHANGED from Phase 11)
 *   3. AiOnboardingUploadSection (new — fastest path to a complete profile)
 *   4. FamilyHouseholdSection    (new — spouse/dependents/household read-only)
 *   5. HsaShoeboxSection         (new — STORE-03 receipt tracking)
 *
 * Settings keeps account/security — nothing is moved off Settings (additive only).
 *
 * DESIGN (Decision 6/7 — elevate, don't replace):
 *   - sw-* tokens; Inter; shadow-sm; 4px/8px rhythm
 *   - Applied: ui-ux-pro-max "Financial Dashboard — Data-Dense" profile pattern
 *   - Applied: soft-skill — section headers, spacing rhythm, interaction states
 *
 * SKILL USAGE:
 *   - ui-ux-pro-max query: "onboarding upload wizard proposal confirm" → applied
 *     step indicator + upload-first onboarding flow (AiOnboardingUploadSection)
 *   - soft-skill: 16px rhythm, shadow-sm depth, label/value hierarchy
 *
 * EDUCATIONAL FRAMING (UI-03): Inline disclaimer on page + each new section.
 */

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { User, Info, ExternalLink, ArrowRight } from 'lucide-react';
import EnhancedProfileSection from '@/Components/SpendifiAI/EnhancedProfileSection';
import LearnedTaxFactsSection from '@/Components/SpendifiAI/LearnedTaxFactsSection';
import FamilyHouseholdSection from '@/Components/SpendifiAI/FamilyHouseholdSection';
import HsaShoeboxSection from '@/Components/SpendifiAI/HsaShoeboxSection';

// ─── Section divider ──────────────────────────────────────────────────────────
function SectionDivider() {
  return <hr className="border-sw-border/60" />;
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function UserProfileIndex() {
  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">User & Family Profile</h1>
          <p className="text-xs text-sw-dim mt-0.5">Your tax profile and optimization facts</p>
        </div>
      }
    >
      <Head title="My Profile" />

      <div className="max-w-2xl space-y-6">
        {/* Page-level educational disclaimer (UI-03 — inline, non-globally-dismissable) */}
        <div className="flex items-start gap-2.5 rounded-xl border border-sw-info/20 bg-sw-info-light/40 px-4 py-3">
          <Info size={14} className="text-sw-info shrink-0 mt-0.5" />
          <p className="text-[12px] text-sw-text-secondary leading-relaxed">
            <span className="font-semibold text-sw-text">For informational purposes.</span>{' '}
            Profile information helps surface potential tax opportunities for your review.
            Consider consulting a qualified tax professional before making financial decisions based on your profile.
          </p>
        </div>

        {/* Page header card */}
        <div className="rounded-2xl border border-sw-border bg-sw-card shadow-sm px-6 py-5">
          <div className="flex items-center gap-3 mb-1">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-sw-accent to-violet-600 flex items-center justify-center">
              <User size={18} className="text-white" />
            </div>
            <div>
              <p className="text-[15px] font-bold text-sw-text">Tax Profile</p>
              <p className="text-[11px] text-sw-dim">
                AI-assisted profile that may help surface income optimization opportunities
              </p>
            </div>
          </div>

          {/* Note about Settings */}
          <div className="mt-3 flex items-center gap-1.5 text-[11px] text-sw-muted">
            <Info size={11} className="shrink-0" />
            <span>Account security and password settings are in{' '}
              <Link href="/settings" className="text-sw-accent hover:text-sw-accent-hover font-medium inline-flex items-center gap-0.5">
                Settings <ExternalLink size={10} />
              </Link>
            </span>
          </div>
        </div>

        {/* Main sections card */}
        <div className="rounded-2xl border border-sw-border bg-sw-card shadow-sm px-6 py-5 space-y-5">

          {/* Section 1: Enhanced Tax Profile (UNCHANGED — imported as-is) */}
          <EnhancedProfileSection />

          <SectionDivider />

          {/* Section 2: Learned Tax Facts (UNCHANGED — imported as-is) */}
          <LearnedTaxFactsSection />

          <SectionDivider />

          {/* Section 3: Document upload — redirects to Optimize My Income (one-journey consolidation) */}
          <div className="flex items-center justify-between gap-4 py-3">
            <div className="flex items-start gap-3">
              <div className="w-8 h-8 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center shrink-0">
                <Info size={14} className="text-sw-accent" />
              </div>
              <div>
                <p className="text-[13px] font-semibold text-sw-text">Add documents to your profile</p>
                <p className="text-[11px] text-sw-dim mt-0.5">Upload pay stubs and tax documents in Optimize My Income.</p>
              </div>
            </div>
            <a
              href="/optimize"
              className="shrink-0 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition"
            >
              Add documents <ArrowRight size={12} />
            </a>
          </div>

          <SectionDivider />

          {/* Section 4: Family & Household (new, read-only) */}
          <FamilyHouseholdSection />

          <SectionDivider />

          {/* Section 5: HSA Shoebox (new, STORE-03) */}
          <HsaShoeboxSection />
        </div>

        {/* Footer disclaimer (UI-03 — persistent) */}
        <div className="flex items-start gap-2 rounded-xl border border-sw-border/60 bg-sw-surface px-4 py-3">
          <Info size={12} className="text-sw-dim shrink-0 mt-0.5" />
          <p className="text-[10px] text-sw-dim leading-relaxed">
            SpendifiAI profile information is for educational and planning purposes only. Accuracy of
            AI-extracted facts should be verified before use. Nothing in your profile constitutes
            tax, legal, or financial advice. Consult a qualified professional for your specific situation.
          </p>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
