# DESIGN-ELEVATION-SPEC.md
## SpendifiAI — Luxury Lean Design Elevation

**Status:** LOCKED — Implementation reference for all frontend work from Phase 11 onward.
**Decision source:** Decision 12 (enhanced-profile-integration-notes.md, 2026-07-02).
**Governing skills applied:** taste-skill v2 (Section 11.B audit + Section 11.D levers), soft-skill (high-end-visual-design), redesign-skill (audit-first), ui-ux-pro-max (fintech queries).

---

## Design Read

Reading this as: **premium fintech productivity app for freelancers, small-business owners, and W-2 employees optimizing income and taxes.** Mode: PRESERVE BRAND, ELEVATE TO PREMIUM. Audience is trust-sensitive (financial data) — the design must feel capable and expensive, not flashy or playful.

---

## Section 11.B — Audit Findings (Current Blandness Tells)

### Dial Readings — Current State
| Dial | Current | Target | Rationale |
|---|---|---|---|
| DESIGN_VARIANCE | 3 | 6 | Symmetric cards, equal nav weights, identical structures. Target: compositional variety in key surfaces, not chaos. |
| MOTION_INTENSITY | 1 | 4 | Effectively static — only `transition-colors`. Target: tasteful micro-interactions, entrance stagger, spring hover. No cinematic. |
| VISUAL_DENSITY | 5 | 5 | Appropriate for a data-heavy fintech tool — preserve. |

### Tell 1 — Flat Shadow Monoculture (CRITICAL)
Every elevation surface in the codebase uses exactly one shadow value: Tailwind's `shadow-sm` (`0 1px 2px rgba(0,0,0,0.05)`). Cards, sidebar, dropdowns, notification buttons, top header — identical perceived elevation. The card and the page background are indistinguishable in terms of physical depth. This is the single most legible signal that the design was not authored by a designer. A premium fintech product like Stripe, Linear, or Notion uses a 4-level elevation hierarchy where depth communicates importance.

**Evidence in code:** `StatCard` → `shadow-sm`. `SubscriptionCard` → `shadow-sm` (via `rounded-xl border`). `AuthenticatedLayout` header → `shadow-sm`. User dropdown → `shadow-lg` (only outlier). `FindingSummaryCard` (Optimize) → `shadow-sm`.

### Tell 2 — Zero Motion Vocabulary (CRITICAL)
All interactive elements use Tailwind's default `transition` or `transition-colors` (150ms ease, linear by default). No hover transforms (scale, translate-y), no press states (scale-down), no spring physics easing, no entrance animations on cards or page sections. The sidebar collapse uses `transition-all duration-300` which is a linear width change. A user can use the entire app for an hour and feel no physical interaction feedback. "Premium" is largely communicated through motion: the app should feel like it has mass.

**Evidence:** NavItem: `transition-colors` only. Buttons: `transition` only. StatCard: static (no hover state at all). SubscriptionCard: `transition-colors` on the wrapper.

### Tell 3 — Missing Display Type Scale (NOTABLE)
The largest text in production is `text-2xl font-bold` on `StatCard` values and `text-xl`/`text-lg` for page headings. There is no "display" tier — no oversized, tightly-tracked hero numbers for the dashboard financial metrics. The type scale is compressed into a narrow band (11px labels → 24px stat values) with no drama. Financial apps command trust partly through confident number presentation: a $4,200 surplus rendered at 24px feels like a spreadsheet; at 40px with -2px tracking it feels like authority.

Additionally, `font-variant-numeric: tabular-nums` is absent — financial figures will shift layout as digits change width.

### Tell 4 — Single Card Anatomy Recipe (CRITICAL)
Every card across the entire app follows one recipe: `rounded-xl border border-sw-border bg-sw-card shadow-sm p-4/p-5`. State variants change only the border color (e.g., `border-emerald-200/60` for cancelled subscriptions). There is no surface variation, no gradient background, no inner border highlight, no layered depth. The "double bezel" technique (an outer shell + inner core with an inset highlight) is completely absent. The result: cards look like printed labels on a flat board.

### Tell 5 — All-Caps Eyebrow Overuse (SLOP TELL)
The `text-xs font-medium uppercase tracking-wider` pattern appears on every StatCard title, every section header badge in Optimize/Index, every nav label detail, and every status label. This is the canonical AI-default tell — the model reaches for this pattern on every section to signal "label." taste-skill mandates max 1 eyebrow per 3 sections. The current ratio is approximately 1:1 (every card has one). The effect is visual monotony — every surface reads the same weight.

### Tell 6 — Icon Container Monoculture (NOTABLE)
StatCard's icon treatment (`bg-sw-accent-light text-sw-accent rounded-lg w-9 h-9`) is the only styled icon container in the app. Everywhere else icons are raw Lucide SVGs in `text-sw-muted` with no container. There is no semantic icon language: success actions, danger states, and neutral labels all use the same Lucide stroke in `sw-muted`. A premium product uses icon containers as a visual language — glass tints, gradient fills, colored backgrounds — to communicate meaning at a glance.

---

## What to Preserve (Non-Negotiable)

- Brand logo SVG (the gradient bars + upward trend icon in sidebar)
- Inter as the sole typeface — weight/scale usage will expand, the family stays
- `sw-accent` (#2563eb) and its family as the primary recognition color for active states, links, primary CTAs
- All URLs, routes, API shapes, functionality
- Accessibility: WCAG AA contrast on all text, visible focus rings, `prefers-reduced-motion` respected on every animation
- Light mode AND dark mode — all new tokens must ship both variants
- Lucide icon family (already established; changing would require mass replacement)
- `shadow-sm` Tailwind utility name may remain in legacy code — it will be overridden by the new system at the @theme level

---

## Anti-Goals

- No heavy glassmorphism overload — backdrop-blur only on modals, dropdowns, the top header (sticky). Never on scrolling content.
- No palette swap — `sw-accent` stays blue. No warm-craft beige, no AI purple gradient mesh backgrounds.
- No layout rearchitecture — the left sidebar shell is preserved. No top-nav migration.
- No new animation libraries — CSS/Tailwind only for motion tokens. If Motion (framer-motion) is already in package.json, it may be used for complex entrance stagger; no GSAP unless explicitly approved by the owner.
- No new font families — Inter only. Display drama comes from weight + tracking + scale, not a new typeface.
- No full-page redesigns in the elevation pass — the goal is layered polish, not page rewrites.

---

## 2. TOKEN EXTENSIONS — Additive @theme Entries

Add the following block IMMEDIATELY AFTER the existing `@theme { }` block in `/home/spendifi/public_html/resources/css/app.css`. These are purely additive — they extend, never replace, the existing `sw-*` tokens.

**Total new token count: 41 tokens across 5 categories.**

```css
/* ═══════════════════════════════════════════════════════════════════
   DESIGN ELEVATION TOKENS — Decision 12 (2026-07-02)
   All tokens are additive to the existing sw-* system.
   ═══════════════════════════════════════════════════════════════════ */
@theme {

  /* ── Elevation / Shadow Scale ─────────────────────────────────── */
  /* Hue-tinted to sw-text (#0f172a) — never pure black, never harsh */
  /* Level 1: near-surface cards (replaces shadow-sm) */
  --shadow-sw-1: 0 1px 2px rgba(15, 23, 42, 0.06), 0 1px 3px rgba(15, 23, 42, 0.04);
  /* Level 2: hovered/floating cards */
  --shadow-sw-2: 0 4px 8px rgba(15, 23, 42, 0.08), 0 2px 4px rgba(15, 23, 42, 0.04);
  /* Level 3: dropdowns, popovers, modals */
  --shadow-sw-3: 0 10px 24px rgba(15, 23, 42, 0.10), 0 4px 8px rgba(15, 23, 42, 0.06);
  /* Level 4: elevated focus, sidebar (sticky surface) */
  --shadow-sw-4: 0 20px 40px rgba(15, 23, 42, 0.12), 0 8px 16px rgba(15, 23, 42, 0.07);
  /* Accent-tinted: primary CTA buttons, active cards */
  --shadow-sw-accent: 0 4px 14px rgba(37, 99, 235, 0.28), 0 2px 6px rgba(37, 99, 235, 0.14);
  /* Success-tinted: savings/positive metric cards */
  --shadow-sw-success: 0 4px 14px rgba(5, 150, 105, 0.20), 0 2px 6px rgba(5, 150, 105, 0.10);
  /* Inset highlight: inner top edge on premium cards */
  --shadow-sw-inset: inset 0 1px 0 rgba(255, 255, 255, 0.85);

  /* ── Motion Tokens ───────────────────────────────────────────── */
  /* Durations */
  --duration-sw-instant:  80ms;
  --duration-sw-fast:    150ms;
  --duration-sw-base:    220ms;
  --duration-sw-slow:    350ms;
  --duration-sw-reveal:  500ms;
  /* Easings — custom cubic-bezier; no linear, no ease-in-out */
  /* Spring feel for entrances (overshoots slightly, settles) */
  --ease-sw-spring:   cubic-bezier(0.32, 0.72, 0, 1);
  /* Deceleration for exits and transitions */
  --ease-sw-out:      cubic-bezier(0, 0, 0.20, 1);
  /* Smooth acceleration for exits leaving the viewport */
  --ease-sw-in:       cubic-bezier(0.55, 0, 1, 0.45);
  /* Balanced for state changes (hover bg, color) */
  --ease-sw-smooth:   cubic-bezier(0.25, 0.46, 0.45, 0.94);

  /* ── Display Type Scale ─────────────────────────────────────── */
  /* These extend the body/label sizes already in use.             */
  /* Applied ONLY to: page hero numbers, section headings, report  */
  /* summaries. NOT sprinkled on labels or card titles.            */
  --font-size-sw-display-xl: 3rem;        /* 48px — dashboard hero metric */
  --line-height-sw-display-xl: 1;
  --letter-spacing-sw-display-xl: -0.04em;
  --font-weight-sw-display-xl: 800;

  --font-size-sw-display-lg: 2.25rem;     /* 36px — page H1 heading */
  --line-height-sw-display-lg: 1.05;
  --letter-spacing-sw-display-lg: -0.03em;
  --font-weight-sw-display-lg: 700;

  --font-size-sw-display-md: 1.5rem;      /* 24px — section heading */
  --line-height-sw-display-md: 1.2;
  --letter-spacing-sw-display-md: -0.02em;
  --font-weight-sw-display-md: 700;

  --font-size-sw-display-sm: 1.125rem;    /* 18px — sub-section heading */
  --line-height-sw-display-sm: 1.3;
  --letter-spacing-sw-display-sm: -0.01em;
  --font-weight-sw-display-sm: 600;

  /* ── Gradient / Glass Recipes ───────────────────────────────── */
  /* Card surface gradient — barely perceptible, adds warmth */
  --gradient-sw-card:    linear-gradient(to bottom, #ffffff 0%, #f8fafc 100%);
  /* Accent glass card (report callouts, premium surfaces) */
  --gradient-sw-accent-glass: linear-gradient(135deg, rgba(37,99,235,0.05) 0%, rgba(124,58,237,0.04) 100%);
  /* Success glass (savings panels) */
  --gradient-sw-success-glass: linear-gradient(135deg, rgba(5,150,105,0.05) 0%, rgba(16,185,129,0.03) 100%);
  /* Sidebar accent line (active indicator gradient) */
  --gradient-sw-accent-bar: linear-gradient(to bottom, #2563eb 0%, #7c3aed 100%);

  /* ── Refined Surfaces (Light → Dark prepared) ───────────────── */
  /* Card inner highlight ring (the "bezel" top edge) */
  --color-sw-card-ring:  rgba(255, 255, 255, 0.80);
  /* Outer card frame (very subtle, wraps the card) */
  --color-sw-card-frame: rgba(15, 23, 42, 0.06);
  /* Hover lift background tint on cards */
  --color-sw-card-hover-deep: #eef2f8;
  /* Icon container backgrounds (semantic tints) */
  --color-sw-icon-accent:   #eff6ff;
  --color-sw-icon-success:  #ecfdf5;
  --color-sw-icon-danger:   #fef2f2;
  --color-sw-icon-warning:  #fffbeb;
  --color-sw-icon-neutral:  #f1f5f9;
  /* Tabular numeric (apply on all financial figures) */
  --font-variant-numeric-sw: tabular-nums;
}

/* ── Prefers-reduced-motion master override ───────────────────── */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration:   0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration:  0.01ms !important;
  }
}

/* ── Utility helpers (not in @theme — direct CSS rules) ─────── */
/* Tabular numerals on all financial data spans */
.font-tabular { font-variant-numeric: tabular-nums; }

/* Card surface gradient utility */
.card-surface {
  background: var(--gradient-sw-card);
  box-shadow: var(--shadow-sw-inset), var(--shadow-sw-1);
}

/* Premium card hover lift */
.card-lift {
  transition: box-shadow var(--duration-sw-base) var(--ease-sw-smooth),
              transform var(--duration-sw-base) var(--ease-sw-smooth);
}
.card-lift:hover {
  box-shadow: var(--shadow-sw-inset), var(--shadow-sw-2);
  transform: translateY(-1px);
}

/* Spring button press state */
.btn-press {
  transition: transform var(--duration-sw-fast) var(--ease-sw-spring),
              box-shadow var(--duration-sw-fast) var(--ease-sw-smooth);
}
.btn-press:active {
  transform: scale(0.98) translateY(1px);
}
```

---

## 3. COMPONENT TREATMENTS — Before → After Class Recipes

All recipes below are copy-paste ready. Apply exactly as written. Do not introduce additional spacing or layout changes beyond what is specified — scope discipline is mandatory.

### 3.1 — App Shell / Sidebar

**Before (current):**
```tsx
<aside className={`hidden sm:flex flex-col bg-sw-sidebar border-r border-sw-border shrink-0 transition-all duration-300 ${collapsed ? 'w-[68px]' : 'w-64'}`}>
```

**After:**
```tsx
<aside className={`hidden sm:flex flex-col bg-sw-sidebar border-r border-sw-card-frame shrink-0 [transition:width_220ms_cubic-bezier(0.32,0.72,0,1)] shadow-[1px_0_0_rgba(15,23,42,0.04)] ${collapsed ? 'w-[68px]' : 'w-64'}`}>
```

Key changes: duration-300 → 220ms spring cubic-bezier; border from `sw-border` to fractionally lighter `sw-card-frame` shadow; add `shadow-[1px_0_0...]` for a soft right-edge separator instead of hard border.

---

**NavItem active state (before):**
```
bg-sw-accent/10 text-sw-accent border-l-2 border-sw-accent
```

**NavItem active state (after):**
```
bg-gradient-to-r from-sw-accent/12 to-transparent text-sw-accent rounded-lg
before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-[3px] before:rounded-full before:bg-gradient-to-b before:from-sw-accent before:to-violet-600
```

The `border-l-2` (hard left line) is replaced with a short rounded pill indicator using `::before` pseudo-element with the gradient from the logo. The background is a subtle gradient fade toward transparent, not a solid rectangle.

**NavItem hover (before):** `hover:text-sw-text hover:bg-sw-card`
**NavItem hover (after):** `hover:text-sw-text hover:bg-sw-surface/80 transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]`

---

### 3.2 — Top Header

**Before:**
```tsx
<header className="shrink-0 h-16 flex items-center justify-between px-6 border-b border-sw-border bg-sw-card shadow-sm">
```

**After:**
```tsx
<header className="shrink-0 h-14 flex items-center justify-between px-6 border-b border-sw-border/60 bg-sw-card/90 backdrop-blur-sm shadow-[0_1px_0_rgba(15,23,42,0.06),0_2px_8px_rgba(15,23,42,0.04)]">
```

Key changes: `h-16` → `h-14` (tighter, more premium feel); `shadow-sm` → custom 2-layer shadow; add `backdrop-blur-sm` (header is a sticky/fixed surface — soft-skill allows blur here); `bg-sw-card/90` for glass feel without hurting legibility.

**User avatar button (before):**
```tsx
<button className="w-9 h-9 rounded-lg bg-gradient-to-br from-purple-500 to-blue-500 flex items-center justify-center text-white text-sm font-bold cursor-pointer">
```
**After:**
```tsx
<button className="w-9 h-9 rounded-xl bg-gradient-to-br from-sw-accent to-violet-600 flex items-center justify-center text-white text-sm font-bold cursor-pointer ring-2 ring-sw-accent/20 ring-offset-1 transition-all [transition-duration:150ms] hover:ring-sw-accent/40 hover:shadow-sw-accent active:scale-95">
```
The `ring` + `ring-offset` adds depth and "premium avatar" feel. `active:scale-95` adds tactile press.

**Notification bell button (before):**
```tsx
<button className="relative w-9 h-9 rounded-lg border border-sw-border bg-transparent flex items-center justify-center text-sw-muted hover:text-sw-text transition">
```
**After:**
```tsx
<button className="relative w-9 h-9 rounded-xl border border-sw-border/80 bg-sw-surface/60 flex items-center justify-center text-sw-muted hover:text-sw-text hover:border-sw-border-strong hover:bg-sw-surface hover:shadow-sw-1 transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)] active:scale-95">
```

---

### 3.3 — StatCard (Metric Cards)

**Before (full class set):**
```tsx
<div className="relative overflow-hidden rounded-xl border border-sw-border bg-sw-card p-5 shadow-sm flex-1 sm:min-w-[200px] min-w-0">
  {/* Icon container */}
  <div className="w-9 h-9 rounded-lg flex items-center justify-center bg-sw-accent-light text-sw-accent">
  {/* Title */}
  <span className="text-xs text-sw-muted font-medium uppercase tracking-wider">{title}</span>
  {/* Value */}
  <div className="text-2xl font-bold text-sw-text tracking-tight">{value}</div>
```

**After:**
```tsx
{/* Outer frame — the "bezel" wrapper */}
<div className="relative overflow-hidden rounded-xl p-px bg-gradient-to-b from-sw-border/80 to-sw-border/40 shadow-sw-1 flex-1 sm:min-w-[200px] min-w-0 card-lift">
  {/* Inner core */}
  <div className="rounded-[calc(0.75rem-1px)] bg-gradient-to-b from-white to-slate-50/60 p-5 h-full shadow-sw-inset">
    {/* Icon container — use semantic color by prop */}
    <div className="w-10 h-10 rounded-xl flex items-center justify-center bg-gradient-to-br from-sw-icon-accent to-blue-100/80 text-sw-accent ring-1 ring-sw-accent-muted/60 shadow-[inset_0_1px_0_rgba(255,255,255,0.9)]">
    {/* Title — sentence-case, NOT all-caps; reduced tracking */}
    <span className="text-[11px] text-sw-muted font-medium tracking-[0.02em]">{title}</span>
    {/* Value — display scale; tabular numerals */}
    <div className="text-[28px] font-[800] text-sw-text tracking-[-0.035em] leading-none font-tabular mt-1">{value}</div>
```

**StatCard icon container — semantic variants (add `iconVariant` prop):**
| Variant | Classes |
|---|---|
| `accent` (default) | `bg-gradient-to-br from-sw-icon-accent to-blue-100/80 text-sw-accent ring-1 ring-sw-accent-muted/60` |
| `success` | `bg-gradient-to-br from-sw-icon-success to-emerald-100/80 text-sw-success ring-1 ring-emerald-200/60` |
| `danger` | `bg-gradient-to-br from-sw-icon-danger to-red-100/80 text-sw-danger ring-1 ring-red-200/60` |
| `warning` | `bg-gradient-to-br from-sw-icon-warning to-amber-100/80 text-sw-warning ring-1 ring-amber-200/60` |
| `neutral` | `bg-gradient-to-br from-sw-icon-neutral to-slate-100/80 text-sw-muted ring-1 ring-sw-border` |

---

### 3.4 — Content Cards (Generic)

All cards using `rounded-xl border border-sw-border bg-sw-card shadow-sm p-4/p-5` should receive:

**After (no hover variant — static content card):**
```tsx
className="rounded-xl bg-gradient-to-b from-white to-slate-50/50 ring-1 ring-sw-border/70 shadow-sw-1 p-5"
```
Note: `border` replaced by `ring-1` (ring renders on top layer, consistent with all inner borders). Shadow upgraded from `shadow-sm` → `shadow-sw-1`.

**After (interactive/hoverable content card):**
```tsx
className="rounded-xl bg-gradient-to-b from-white to-slate-50/50 ring-1 ring-sw-border/70 shadow-sw-1 p-5 card-lift cursor-pointer"
```

**SubscriptionCard state variants (before):**
```
border-sw-border bg-sw-card hover:border-sw-border-strong     (active)
border-emerald-200/60 bg-sw-card/60                            (cancelled)
border-amber-300 bg-amber-50/30                                (recharged)
border-slate-300 bg-slate-50/40                                (missed)
```

**After:**
```
ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1     (active — hover adds card-lift)
ring-1 ring-emerald-200 bg-gradient-to-b from-white to-emerald-50/30 shadow-sw-success  (cancelled+saved)
ring-1 ring-amber-300 bg-gradient-to-b from-amber-50/40 to-white shadow-[0_2px_8px_rgba(217,119,6,0.12)]  (recharged)
ring-1 ring-slate-200 bg-gradient-to-b from-slate-50/50 to-white shadow-sw-1        (missed)
```

---

### 3.5 — Primary Buttons / CTAs

**Before:**
```tsx
className="rounded-lg bg-sw-accent px-4 py-2 text-sm font-semibold text-white hover:bg-sw-accent-hover transition"
```

**After:**
```tsx
className="rounded-lg bg-sw-accent px-4 py-2 text-sm font-semibold text-white shadow-sw-accent
  hover:bg-sw-accent-hover hover:shadow-[0_4px_16px_rgba(37,99,235,0.36)]
  active:scale-[0.98] active:translate-y-px active:shadow-sw-accent
  transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]
  focus-visible:ring-2 focus-visible:ring-sw-accent/60 focus-visible:ring-offset-2"
```

**Secondary/Ghost buttons — before:**
```
rounded-lg border border-sw-border bg-transparent px-4 py-2 text-sm text-sw-muted hover:text-sw-text transition
```

**After:**
```
rounded-lg border border-sw-border/80 bg-sw-surface/60 px-4 py-2 text-sm text-sw-muted
  hover:text-sw-text hover:border-sw-border-strong hover:bg-sw-surface hover:shadow-sw-1
  active:scale-[0.98]
  transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]
  focus-visible:ring-2 focus-visible:ring-sw-border focus-visible:ring-offset-2
```

**Danger buttons (cancel/delete):**
```
rounded-lg bg-sw-danger/10 border border-sw-danger/30 px-4 py-2 text-sm font-medium text-sw-danger
  hover:bg-sw-danger/20 hover:border-sw-danger/50 hover:shadow-[0_2px_8px_rgba(220,38,38,0.16)]
  active:scale-[0.98]
  transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]
```

---

### 3.6 — Page Headers

Every page should open with a two-line heading block instead of an `<h1>` with default sizing.

**Before (typical, e.g., Dashboard):**
```tsx
<h1 className="text-xl font-semibold text-sw-text">Dashboard</h1>
```

**After (in-page header, above the stat cards):**
```tsx
<div className="mb-6">
  <h1 className="text-[28px] font-[800] text-sw-text tracking-[-0.03em] leading-tight">{pageTitle}</h1>
  {subtitle && (
    <p className="mt-1 text-[13px] text-sw-muted leading-relaxed max-w-[520px]">{subtitle}</p>
  )}
</div>
```

Note: Do NOT use `text-sw-display-lg` as a Tailwind class — the @theme `font-size` custom properties require a Tailwind v4 plugin or direct inline style. Use the explicit pixel size in the class instead.

---

### 3.7 — Badges

**Before:**
```
bg-sw-danger text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full
```

**After:**
```
bg-sw-danger text-white text-[10px] font-[700] px-2 py-0.5 rounded-full shadow-[0_1px_3px_rgba(220,38,38,0.30)] ring-1 ring-white/20
```

The `ring-1 ring-white/20` gives a "glass edge" highlight. The accent shadow grounds it.

**Status badges (success/warning/info/neutral):**

| Variant | Before | After |
|---|---|---|
| Success | `bg-sw-success-light text-sw-success` | `bg-gradient-to-br from-sw-icon-success to-emerald-100/60 text-sw-success ring-1 ring-emerald-200/60 shadow-[0_1px_2px_rgba(5,150,105,0.12)]` |
| Warning | `bg-sw-warning-light text-sw-warning` | `bg-gradient-to-br from-sw-icon-warning to-amber-100/60 text-sw-warning ring-1 ring-amber-200/60 shadow-[0_1px_2px_rgba(217,119,6,0.12)]` |
| Info | `bg-sw-info-light text-sw-info` | `bg-gradient-to-br from-sw-info-light to-violet-100/60 text-sw-info ring-1 ring-violet-200/60 shadow-[0_1px_2px_rgba(124,58,237,0.12)]` |
| Neutral | `bg-sw-surface text-sw-muted` | `bg-gradient-to-br from-sw-icon-neutral to-slate-100/60 text-sw-muted ring-1 ring-sw-border shadow-[0_1px_2px_rgba(15,23,42,0.06)]` |

---

### 3.8 — Tables / Transaction Rows

**Row hover (before):**
```
hover:bg-sw-card-hover
```

**Row hover (after):**
```
hover:bg-gradient-to-r hover:from-sw-accent-light/30 hover:to-transparent
transition-colors [transition-duration:100ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]
```

The gradient fade toward transparent on table rows produces a subtle but distinct premium hover over a flat background-color change.

**Table header cells (before):**
```
text-xs font-medium uppercase tracking-wider text-sw-muted
```

**After (drop the all-caps, use medium weight at 11px):**
```
text-[11px] font-[600] tracking-[0.02em] text-sw-muted
```

---

### 3.9 — Empty States

Replace any blank-on-white empty state with the following structure. Premium empty states are composed, not absent.

**Premium empty state template:**
```tsx
<div className="flex flex-col items-center justify-center py-16 px-6 text-center">
  {/* Icon container — larger for empty states */}
  <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-sw-icon-neutral to-slate-100/80 ring-1 ring-sw-border flex items-center justify-center shadow-sw-1 mb-4">
    <Icon size={24} className="text-sw-dim" />
  </div>
  <h3 className="text-[15px] font-[600] text-sw-text tracking-[-0.01em] mb-1.5">{title}</h3>
  <p className="text-[13px] text-sw-muted leading-relaxed max-w-[320px] mb-5">{description}</p>
  {/* Primary CTA — use button-after recipe from 3.5 */}
  <button className="rounded-lg bg-sw-accent px-4 py-2 text-sm font-semibold text-white shadow-sw-accent hover:bg-sw-accent-hover hover:shadow-[0_4px_16px_rgba(37,99,235,0.36)] active:scale-[0.98] transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]">
    {ctaLabel}
  </button>
</div>
```

---

### 3.10 — Loading Skeletons (Replace Spinners)

`Loader2` (Lucide `animate-spin`) should be replaced for page-level loading states. Use skeleton placeholders that match the target layout shape. For inline button loading states, the spinner remains acceptable.

**Skeleton template (card shape):**
```tsx
<div className="rounded-xl ring-1 ring-sw-border/60 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-5 animate-pulse">
  <div className="flex items-center gap-3 mb-4">
    <div className="w-10 h-10 rounded-xl bg-sw-surface" />
    <div className="h-3 w-24 rounded-full bg-sw-surface" />
  </div>
  <div className="h-8 w-32 rounded-lg bg-sw-surface mb-2" />
  <div className="h-3 w-20 rounded-full bg-sw-surface" />
</div>
```

**Skeleton stat-card row (4 cards):**
```tsx
<div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
  {[...Array(4)].map((_, i) => (
    <div key={i} className="rounded-xl ring-1 ring-sw-border/60 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-5 animate-pulse">
      <div className="flex items-center gap-2.5 mb-4">
        <div className="w-10 h-10 rounded-xl bg-sw-surface" />
        <div className="h-3 w-20 rounded-full bg-sw-surface" />
      </div>
      <div className="h-8 w-28 rounded-lg bg-sw-surface" />
    </div>
  ))}
</div>
```

`animate-pulse` is a Tailwind utility (CSS opacity oscillation) — no JS needed, respects `prefers-reduced-motion` when the override in section 2 is applied.

---

### 3.11 — Report / Scenario Surfaces (Born-Premium)

The Optimize/Index.tsx `FindingSummaryCard` and future scenario cards should be born to the new spec, not retroactively polished.

**FindingSummaryCard (current):**
```tsx
<div className="rounded-2xl border border-sw-border bg-sw-card shadow-sm p-5 space-y-3">
```

**After (born-premium):**
```tsx
<div className="rounded-2xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/40 shadow-sw-2 p-5 space-y-3 card-lift">
```

**Section-type icon container in FindingSummaryCard (current):**
```tsx
<div className="w-9 h-9 rounded-xl bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center shrink-0">
  <BarChart2 size={16} className="text-sw-accent" />
</div>
```

**After:**
```tsx
<div className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0
  bg-gradient-to-br from-sw-icon-accent to-blue-100/80 text-sw-accent
  ring-1 ring-sw-accent-muted/60 shadow-[inset_0_1px_0_rgba(255,255,255,0.9)]">
  <BarChart2 size={16} className="text-sw-accent" />
</div>
```

**Scenario/checklist action card (new surfaces — use this as the canonical recipe):**
```tsx
<div className="group rounded-xl ring-1 ring-sw-border/70 bg-gradient-to-b from-white to-slate-50/50 shadow-sw-1 p-4 card-lift">
  {/* Benefit line — high-visibility */}
  <div className="flex items-baseline gap-1.5 mb-2">
    <span className="text-[22px] font-[800] text-sw-success tracking-[-0.03em] leading-none font-tabular">
      {benefitAmount}
    </span>
    <span className="text-[11px] text-sw-muted">{benefitLabel}</span>
  </div>
  {/* Action title */}
  <p className="text-[14px] font-[600] text-sw-text tracking-[-0.01em] leading-snug">{actionTitle}</p>
  {/* Description */}
  <p className="text-[12px] text-sw-muted mt-1 leading-relaxed">{description}</p>
</div>
```

---

## 4. MOTION VOCABULARY

All motion in the app must be CSS/Tailwind only. No new libraries unless Motion (framer-motion) is confirmed in package.json, in which case it may be used ONLY for staggered list reveals.

### 4.1 — Hover / Focus / Press States (apply to all interactive elements)

Every interactive element must implement three states, not just hover:

| State | CSS |
|---|---|
| Hover | Transform + shadow upgrade (see 3.5 for buttons, `card-lift` for cards) |
| Focus visible | `focus-visible:ring-2 focus-visible:ring-sw-accent/60 focus-visible:ring-offset-2` |
| Active/Press | `active:scale-[0.98] active:translate-y-px` (buttons); `active:scale-[0.99]` (cards) |
| Disabled | `disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none` |

### 4.2 — Entrance Transitions (Page Load, Tab Switch)

Cards in a grid should stagger in on mount using CSS animation delays (no JS required):

```css
/* In app.css — stagger utility */
.stagger-children > * {
  animation: sw-fade-up var(--duration-sw-reveal) var(--ease-sw-spring) both;
}
.stagger-children > *:nth-child(1) { animation-delay: 0ms; }
.stagger-children > *:nth-child(2) { animation-delay: 40ms; }
.stagger-children > *:nth-child(3) { animation-delay: 80ms; }
.stagger-children > *:nth-child(4) { animation-delay: 120ms; }
.stagger-children > *:nth-child(5) { animation-delay: 160ms; }
.stagger-children > *:nth-child(n+6) { animation-delay: 200ms; }

@keyframes sw-fade-up {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .stagger-children > * { animation: none; }
}
```

**Usage:**
```tsx
<div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stagger-children">
  {statCards}
</div>
```

Apply `stagger-children` to: stat card grids, finding summary card lists, checklist action lists. Do NOT apply to table rows (too many items — performance issue) or to individual text runs.

### 4.3 — Expand/Collapse Transitions

Current expand/collapse (e.g., SubscriptionCard's action panel, admin nav group) uses instant show/hide via conditional rendering. Upgrade these to CSS height transitions:

**Pattern (max-height transition):**
```tsx
<div
  className={`overflow-hidden transition-[max-height,opacity] [transition-duration:220ms] [transition-timing-function:cubic-bezier(0.32,0.72,0,1)] ${
    isOpen ? 'max-h-[400px] opacity-100' : 'max-h-0 opacity-0'
  }`}
>
  {children}
</div>
```

The `max-h-[400px]` value should be generous enough to not clip content. This produces a spring-like reveal without JS measurement.

### 4.4 — Loading State Transitions

When data refreshes (e.g., `useApi` refresh on a dashboard card), instead of a full spinner overlay, use an opacity pulse on the existing card:

```tsx
<div className={`card-surface rounded-xl p-5 transition-opacity [transition-duration:150ms] ${isLoading ? 'opacity-60' : 'opacity-100'}`}>
  {content}
</div>
```

### 4.5 — prefers-reduced-motion Compliance

The global override in section 2's @theme block (`animation-duration: 0.01ms`) handles all CSS animations. For any future Motion/Framer usage:

```tsx
import { useReducedMotion } from 'motion/react';

const shouldReduce = useReducedMotion();
// Pass to: initial={shouldReduce ? false : { opacity: 0, y: 8 }}
```

---

## 5. PRIORITY-ORDERED ROLLOUT PLAN

### Wave 1 — Tokens + Shell (Effort: ~3–4h, lowest risk)

**Goal:** All shadow levels, motion tokens, gradient recipes, and the sidebar/header polish deployed. Zero functional change.

**Files to touch:**
- `resources/css/app.css` — add the full @theme extension block from section 2
- `resources/js/Layouts/AuthenticatedLayout.tsx` — sidebar transition, top header classes, nav item active state, user avatar, bell button

**Wave 1 Audit Checklist:**
- [ ] `npm run build` passes with zero errors
- [ ] All `sw-shadow-*` tokens resolve in browser DevTools as CSS vars
- [ ] Sidebar collapse animation uses spring cubic-bezier (visually verify: should feel elastic, not linear)
- [ ] Active nav item shows pill indicator, NOT `border-l-2`
- [ ] Top header has backdrop-blur visible on scroll
- [ ] All hover states on nav items have no jarring color flash
- [ ] Dark mode: verify sidebar and header do not turn white in dark mode (verify existing dark variants still apply)
- [ ] Reduced-motion: disable animations in browser prefs, confirm sidebar collapses instantly

---

### Wave 2 — Core Dashboard Surfaces (Effort: ~5–7h, medium scope)

**Goal:** StatCard, content cards, buttons, badges upgraded across Dashboard, Subscriptions, Transactions pages.

**Files to touch:**
- `resources/js/Components/SpendifiAI/StatCard.tsx` — double-bezel anatomy, display value size, icon container gradient, drop all-caps label
- `resources/js/Components/SpendifiAI/SubscriptionCard.tsx` — ring-1 + gradient background, state variants, card-lift, button upgrades
- `resources/js/Components/SpendifiAI/Badge.tsx` — add gradient+ring variant treatments from 3.7
- `resources/js/Pages/Dashboard.tsx` — apply `stagger-children` to stat card grid; upgrade any inline card divs to card-surface/card-lift recipe; add `font-tabular` to financial figure spans
- `resources/js/Pages/Subscriptions.tsx` — grid card-lift, stat bars
- `resources/js/Pages/Transactions.tsx` — table row hover gradient

**Wave 2 Audit Checklist:**
- [ ] `npm run build` and `php artisan test --compact` pass
- [ ] StatCard values use `text-[28px] font-[800]` + `font-tabular`; title is NOT all-caps
- [ ] `card-lift` hover produces visible `-1px translateY` on SubscriptionCards
- [ ] Cancelled subscription card reads emerald-tinted, recharged reads amber-tinted (visual hierarchy preserved)
- [ ] Primary CTAs have accent shadow + hover deepens it
- [ ] No broken hover states on mobile (card-lift disabled on touch: verify at 375px)
- [ ] All badge variants render correctly in both light/dark mode
- [ ] Dashboard stat grid staggers in on page load (verify by hard-refresh)
- [ ] No layout shift from stagger animation (elements are in DOM, just opacity/Y)
- [ ] `prefers-reduced-motion` disables all stagger (verify with browser flag)

---

### Wave 3 — Feature Surfaces (Effort: ~4–6h, new UI born-premium)

**Goal:** Optimize/Index, report cards, scenario surfaces, action checklists, and all new P11/P12 UI are born to the spec. Empty states and loading skeletons across all pages.

**Files to touch:**
- `resources/js/Components/SpendifiAI/InterviewCard.tsx` — card-lift recipe, icon containers
- `resources/js/Components/SpendifiAI/OptimizationReportView.tsx` — section cards use shadow-sw-2, icon containers semantic
- `resources/js/Pages/Optimize/Index.tsx` — `FindingSummaryCard` upgraded to born-premium recipe; page header uses display heading
- All new scenario/checklist components (future) — use canonical recipe from 3.11
- Empty state: every page audit for blank states → replace with premium empty state template from 3.9
- Loading skeleton: replace `Loader2` full-page spinners → skeleton cards per 3.10

**Wave 3 Audit Checklist:**
- [ ] `npm run build` and `php artisan test --compact` pass
- [ ] Optimize page header has `text-[28px] font-[800] tracking-[-0.03em]`
- [ ] `FindingSummaryCard` has `shadow-sw-2` and `card-lift`
- [ ] Scenario/checklist cards use benefit amount at `text-[22px] font-[800] text-sw-success font-tabular`
- [ ] All empty states have icon container + heading + description + CTA (none are blank)
- [ ] Full-page loading states are skeletons, not centered spinners
- [ ] No eyebrow (all-caps tracking-wider) in 2 consecutive sections on any page
- [ ] `font-tabular` applied to all financial figures (amounts, percentages)
- [ ] Reduced-motion: verify all stagger/reveal animations disable cleanly

---

## 6. PRESERVATION AUDIT TEMPLATE

Run this audit before closing any wave. A single violation is a blocking failure.

```
PRESERVATION AUDIT — Wave [N]
Date: [DATE]
Files changed: [list]

URLs unchanged:       [ ] PASS  [ ] FAIL
Nav labels unchanged: [ ] PASS  [ ] FAIL
Form field names unchanged: [ ] PASS  [ ] FAIL
Page anchors unchanged:     [ ] PASS  [ ] FAIL
sw-accent (#2563eb) family still primary: [ ] PASS  [ ] FAIL
Inter typeface still used everywhere:     [ ] PASS  [ ] FAIL
Brand logo SVG unchanged:                 [ ] PASS  [ ] FAIL
All functionality preserved:              [ ] PASS  [ ] FAIL
Dark mode verified:                       [ ] PASS  [ ] FAIL
WCAG AA contrast on new surfaces:         [ ] PASS  [ ] FAIL
prefers-reduced-motion respected:         [ ] PASS  [ ] FAIL

RESULT: [ ] ALL PASS — proceed  [ ] FAIL — block and fix before commit
```

---

## 7. EXPLICIT ANTI-GOALS (What This Spec Does NOT Do)

- Does NOT add glassmorphism to page sections (backdrop-blur only on the sticky header and dropdowns)
- Does NOT introduce a new font family
- Does NOT palette-swap sw-accent or add a warm/gold accent color
- Does NOT change the left-sidebar layout architecture
- Does NOT add GSAP, Motion, or any animation library that is not already in package.json
- Does NOT rearchitect page layouts (Dashboard sections, Subscriptions grid, etc.)
- Does NOT change any existing API response shape, model field, or route
- Does NOT use Inter Serif or any serif variant — no mixed-family emphasis
- Does NOT add eyebrow labels (uppercase tracking-wider) to new surfaces — use sentence-case headings
- Does NOT break existing test assertions — `php artisan test --compact` must pass after every wave
