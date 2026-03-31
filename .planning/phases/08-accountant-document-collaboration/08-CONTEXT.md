# Phase 8: Accountant Document Collaboration - Context

**Gathered:** 2026-03-31
**Status:** Ready for planning

<domain>
## Phase Boundary

Accountants can register their firm, invite clients via branded links, view client documents, annotate them with threaded comments, request missing documents, and track client readiness from a dedicated dashboard. Document vault (Phase 6), AI extraction (Phase 7), and intelligence layer (Phase 9) are separate phases.

</domain>

<decisions>
## Implementation Decisions

### Firm Registration & Structure
- New AccountingFirm model with name, address, phone, branding (logo URL, primary color)
- Accountant users belong to a firm (accountant_id -> accounting_firm_id foreign key on users or separate pivot)
- Firm registration is a dedicated flow: accountant creates firm, then invites clients from firm context
- Existing AccountantClient model extended — clients managed at firm level, not individual accountant level (ACCT-02)
- Branded invite links generated per-firm with unique token — client self-registers and auto-links to the firm

### Client Invite Flow
- Firm generates branded invite links (unique URL with firm token)
- Client clicks link → registers (or logs in if existing) → automatically linked to the firm
- Existing AccountantInviteMail extended for branded firm invites (firm name, logo, color in email template)
- Invite link page shows firm branding (name, logo) so client knows who invited them

### Document Annotations
- New DocumentAnnotation model: belongs to TaxDocument and User, threaded (parent_id for replies)
- Annotations displayed as a thread on the document detail page (reuses the Audit Log tab area — new "Comments" tab)
- Both accountant and client can add annotations — threaded conversation on a document
- Annotations are timestamped and show author name/role badge (Accountant vs Client)
- New annotations trigger email notification to the other party (ACCT-09)

### Missing Document Requests
- New DocumentRequest model: accountant creates a request with description, links to client and optional tax year/category
- Client sees requests as alerts in their vault view (extends MissingAlertBanner from Phase 6)
- Each request has status: pending → uploaded → dismissed
- When client uploads a document that matches a request, request auto-updates to "uploaded"
- Request creation triggers email notification to client (ACCT-09)

### Accountant Dashboard
- New Accountant/Dashboard.tsx page replacing or extending the existing Clients.tsx
- Stats bar at top: total clients, documents pending review, missing requests open, upcoming deadlines
- Client list table with: name, document count, completeness percentage, last activity, status badge
- Deadline tracker section (tax filing deadlines per client)
- Invite link generator: button to copy firm's branded invite URL
- Click client row → view their documents (existing impersonation or scoped view)

### Email Notifications (ACCT-09)
- 5 Mail classes total:
  1. FirmInviteMail — branded invite to client (extends existing AccountantInviteMail pattern)
  2. DocumentRequestMail — accountant requests missing doc from client
  3. AnnotationNotifyMail — new annotation on a document (notify other party)
  4. DocumentUploadedMail — client uploads a document (notify accountant)
  5. RequestFulfilledMail — client fulfills a document request (notify accountant)

### Cross-Role Authorization (TEST-04)
- Owner can access own documents — existing policy
- Linked accountant can access client documents — existing TaxDocumentPolicy already has accountant logic
- Unlinked accountant BLOCKED from accessing other clients' documents — must verify in tests
- Annotations only visible to document owner and their linked accountant
- Document requests only creatable by linked accountant for their client

### Claude's Discretion
- Exact dashboard layout and responsive breakpoints
- Annotation thread styling and threading depth limits
- Deadline tracker data source and display format
- Document completeness calculation algorithm
- Invite link expiration policy
- How "matching" works for auto-fulfilling document requests

</decisions>

<specifics>
## Specific Ideas

- Existing AccountantController, AccountantClient model, Clients.tsx page, and AccountantInviteMail provide solid foundation — extend don't rebuild
- EnsureAccountant middleware already exists for role gating
- Impersonation system (ImpersonationContext) already allows accountant to view client data — leverage for document access
- UserType enum already has Personal/Accountant distinction
- Phase 6's MissingAlertBanner component can be extended to show document requests alongside AI-detected missing docs
- Phase 7's document detail page already has tabs — add "Comments" tab for annotations

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AccountantClient` model (`app/Models/AccountantClient.php`): Client-accountant relationships with active/pending status and scopes
- `AccountantController` (`app/Http/Controllers/Api/AccountantController.php`): Client listing, invite sending, existing endpoints
- `AccountantInviteMail` (`app/Mail/AccountantInviteMail.php`): Email invite template — extend for firm branding
- `EnsureAccountant` middleware: Role-gating for accountant routes
- `Accountant/Clients.tsx`: Existing client management page with search, tax download, invite flow
- `ImpersonationContext` + `ImpersonationController`: Accountant can view client data
- `UserType` enum: Personal/Accountant distinction
- `TaxDocumentPolicy`: Already has accountant access logic
- `MissingAlertBanner` component (Phase 6): Can show document requests
- `Vault/Show.tsx` (Phase 7): Document detail with tabs — add Comments tab
- `Badge` component: Role badges for annotation authors

### Established Patterns
- Mail classes in `app/Mail/` with Blade templates in `resources/views/mail/`
- Controller + FormRequest + Policy pattern for authorization
- useApi/useApiPost hooks for frontend data fetching
- Inertia pages with AuthenticatedLayout
- sw-* Tailwind tokens for consistent styling

### Integration Points
- AccountingFirm linked to users via firm_id column on users table
- DocumentAnnotation linked to TaxDocument (hasMany)
- DocumentRequest linked to User (client) and AccountingFirm
- Accountant dashboard at `/accountant/dashboard` — Inertia page
- API routes under `/api/v1/accountant/` prefix (existing pattern)
- Firm invite link at `/invite/{token}` — public route

</code_context>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 08-accountant-document-collaboration*
*Context gathered: 2026-03-31*
