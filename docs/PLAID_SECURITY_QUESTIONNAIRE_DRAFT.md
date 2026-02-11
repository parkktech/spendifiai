# Plaid Security Questionnaire — Draft Answers
**Application:** [App Name TBD] (AI-Powered Expense Tracker)
**Date:** February 2026

> These are draft answers for you to review and customize before submitting.
> Items marked ⚠️ need your input. Items marked ✅ are based on what we've built.

---

## Governance and Risk Management

### Q1: Contact information for resource(s) responsible for information security
*Include name, title and email address.*

⚠️ **YOU FILL IN:**

```
Name:    Jason [Last Name]
Title:   Founder & Lead Developer
Email:   security@[yourdomain].app

Group monitoring address: security@[yourdomain].app
```

> TIP: Even as a solo developer, use a role-based alias (security@) rather than
> a personal email. Forward it to yourself. Plaid sees this as more professional
> and it scales when you add team members.

---

### Q2: Does your organization have a documented information security policy and procedures?
*Operationalized to identify, mitigate, and monitor information security risks.*

**Recommended answer: Yes**

**Supporting detail you can provide:**

```
Yes. Our information security program includes:

- A written Information Security Policy covering data classification, access
  controls, incident response, and encryption standards.
- Risk assessments conducted prior to integrating third-party services
  (including Plaid) to evaluate data handling requirements.
- Secure development lifecycle (SDLC) practices including code review,
  dependency vulnerability scanning, and environment-based secrets management.
- Consumer financial data received from Plaid is classified as Highly Sensitive
  and subject to encryption-at-rest, least-privilege access controls, and
  defined retention/deletion schedules.
- Security monitoring via application logging, failed authentication tracking,
  and automated alerts for anomalous access patterns.
```

⚠️ **ACTION REQUIRED:** You should actually write a 2-3 page Information
Security Policy document before submitting. Key sections:
1. Purpose & Scope
2. Data Classification (Public, Internal, Confidential, Highly Sensitive)
3. Access Control Standards
4. Encryption Standards
5. Incident Response Procedure
6. Data Retention & Deletion
7. Employee/Contractor Security Requirements
8. Review Schedule (annually)

I can generate this document for you if you want.

---

## Identity and Access Management

### Q3: What access controls does your organization have in place?
*To limit access to production assets (physical or virtual) and sensitive data.*

**Select all that apply** (check whichever Plaid lists that match):

✅ **What we've built / can truthfully claim:**

```
- Role-based access control (RBAC): Application enforces user-level data
  isolation. Users can only access their own financial data via Sanctum
  token authentication and Eloquent model policies.

- Principle of least privilege: Database credentials, API keys, and Plaid
  access tokens are stored as encrypted environment variables, not in code.
  Plaid access tokens are encrypted at-rest using Laravel's AES-256-CBC
  encryption before database storage.

- MFA for infrastructure access: Multi-factor authentication is enabled on
  all cloud provider accounts (AWS/DigitalOcean/etc.), GitHub, and
  database administration tools.

- Secrets management: All sensitive credentials stored in environment
  variables, never committed to version control. .env files are excluded
  via .gitignore. Production secrets managed via [hosting provider's
  secrets manager].

- API token authentication: All API endpoints require Sanctum bearer token
  authentication. Tokens are single-use-per-session (revoked on new login).
  Tokens auto-expire after inactivity.

- Account lockout: After 5 failed login attempts, accounts are locked for
  15 minutes. Failed attempts are tracked and logged.

- Authorization policies: Every data-access endpoint enforces ownership
  verification (user_id matching) via Laravel Policies preventing
  horizontal privilege escalation.
```

⚠️ **You should also have in place before submitting:**
- SSH key-only access to production servers (no password SSH)
- Firewall rules limiting database access to application servers only
- Separate database credentials for production vs. staging

---

### Q4: Does your organization provide MFA for consumers before Plaid Link is surfaced?
*On mobile and/or web applications.*

**Recommended answer: Yes (optional MFA, available to all users)**

```
Yes. Our application offers multi-factor authentication (MFA) via TOTP
(Time-Based One-Time Password) compatible with Google Authenticator, Authy,
and Microsoft Authenticator. Users can enable MFA from their security settings.

When MFA is enabled, users must provide a valid 6-digit TOTP code (or a
one-time recovery code) during login before accessing any application features,
including Plaid Link.

Additionally, we implement:
- Google reCAPTCHA v3 on login and registration to prevent automated attacks.
- Account lockout after 5 failed authentication attempts.
- Session-based token authentication that requires re-authentication after
  token expiration.
```

> NOTE: Plaid doesn't require mandatory MFA for all users, but they want to
> see that it's available. "Optional MFA" is an accepted answer. Our
> implementation (TOTP via Google Authenticator with recovery codes) is the
> industry standard approach.

---

### Q5: Is MFA in place for access to critical systems that store or process consumer financial data?
*Internal/administrative access, not consumer-facing.*

**Recommended answer: Yes**

```
Yes. Multi-factor authentication is required for all access to systems that
store or process consumer financial data:

- Cloud hosting provider console: MFA enforced on account login.
- Database access: Restricted to application-level connections only.
  Direct database administration requires VPN + MFA.
- GitHub/source control: MFA required on all accounts with repository access.
- Domain registrar and DNS: MFA enabled.
- Email accounts used for transactional email: MFA enabled.
- Plaid Dashboard: MFA enabled on Plaid developer account.
```

⚠️ **ACTION REQUIRED:** Make sure MFA is actually enabled on:
- [ ] Your cloud hosting account (AWS, DigitalOcean, etc.)
- [ ] GitHub account
- [ ] Plaid dashboard account
- [ ] Your domain registrar
- [ ] Your email provider (Gmail 2FA is on since you use app passwords)

---

## Infrastructure and Network Security

### Q6: Does your organization encrypt data in-transit using TLS 1.2 or better?
*Between clients and servers.*

**Recommended answer: Yes**

```
Yes. All data transmitted between clients and servers is encrypted using
TLS 1.2 or higher (TLS 1.3 preferred).

- All HTTP traffic is served over HTTPS. HTTP requests are redirected to
  HTTPS via server configuration.
- SSL/TLS certificates are provisioned and auto-renewed via Let's Encrypt
  (or managed by our hosting provider).
- Older TLS versions (1.0, 1.1) and insecure cipher suites are disabled
  in our server configuration.
- All communication with the Plaid API uses HTTPS/TLS as required by
  Plaid's API specifications.
- Internal service communication (application to database, application to
  Redis cache) uses encrypted connections where supported.
```

⚠️ **ACTION REQUIRED:** When you deploy, ensure:
- [ ] HTTPS enforced on your domain with auto-redirect
- [ ] TLS 1.0/1.1 disabled in nginx/Apache config
- [ ] SSL Labs test score of A or better (test at ssllabs.com/ssltest)

---

### Q7: Does your organization encrypt consumer data received from Plaid at-rest?

**Recommended answer: Yes**

```
Yes. Consumer financial data received from the Plaid API is encrypted at-rest
at multiple layers:

1. Application-level encryption: Plaid access tokens are encrypted using
   Laravel's AES-256-CBC encryption (via the encrypt() helper) before being
   stored in the database. The encryption key is derived from the application
   key and stored securely in environment variables, never in source code.

2. Database-level encryption: Our PostgreSQL database is configured with
   encryption at-rest via the hosting provider's managed database service
   (which uses AES-256 volume encryption).

3. Backup encryption: Database backups are encrypted using the hosting
   provider's encryption-at-rest capabilities.

Specific data handling:
- Plaid access_tokens: AES-256-CBC encrypted in application layer before
  database storage.
- Transaction data: Stored in encrypted-at-rest database volumes.
- Account numbers/routing numbers: Not stored. Only masked account
  identifiers (last 4 digits) are retained.
- Consumer PII: Subject to the same encryption-at-rest protections.
```

✅ This is accurate — we built `'plaid_access_token' => 'encrypted'` into the
BankConnection model, and email OAuth tokens are also encrypted.

---

## Development and Vulnerability Management

### Q8: Do you actively perform vulnerability scans?
*Against employee/contractor machines and production assets.*

**Select all practices in place (check what applies):**

```
- Dependency vulnerability scanning: Automated scanning of application
  dependencies via Composer audit (PHP) and npm audit (JavaScript) as part
  of the CI/CD pipeline. Critical vulnerabilities block deployment.

- Automated security updates: Operating system and runtime security patches
  are applied automatically or within 48 hours of release on production
  servers via unattended-upgrades (Ubuntu) or managed hosting auto-updates.

- Code review: All code changes undergo review before deployment to
  production. Security-sensitive changes (authentication, data access,
  API integrations) receive additional scrutiny.

- Secure coding practices: Input validation on all user-facing endpoints
  via Laravel Form Requests. Parameterized queries via Eloquent ORM
  (preventing SQL injection). CSRF protection on web routes. XSS
  prevention via Blade templating auto-escaping.

- Endpoint protection: Development machines run up-to-date operating
  systems with automatic security updates enabled and disk encryption
  (FileVault/BitLocker) active.
```

⚠️ **ACTION REQUIRED — Set up before submitting:**
- [ ] Run `composer audit` and fix any vulnerabilities
- [ ] Set up GitHub Dependabot for automated dependency alerts
- [ ] Enable disk encryption on your development machine(s)
- [ ] Document your patching cadence (e.g., "critical patches within 48 hours")

---

## Privacy

### Q9: Does your organization have a privacy policy?
*For the application where Plaid Link will be deployed. Provide link if available.*

**Recommended answer: Yes**

```
Yes. Our privacy policy is published at:
https://[yourdomain].app/privacy

The policy covers:
- What consumer data we collect and why
- How we use data received from Plaid (specifically calling out Plaid's
  data access)
- Data sharing practices (we do not sell consumer data)
- Consumer rights regarding their data (access, correction, deletion)
- Data retention and deletion schedules
- Security measures in place to protect consumer data
- Contact information for privacy inquiries

Our privacy policy complies with applicable data privacy regulations
including CCPA and references Plaid's end-user privacy policy as required
by Plaid's integration guidelines.
```

⚠️ **ACTION REQUIRED:** You MUST have a published privacy policy before Plaid
approves production access. Key things Plaid looks for:
- [ ] Mention of Plaid by name: "We use Plaid Inc. to connect your bank..."
- [ ] Link to Plaid's privacy policy: https://plaid.com/legal/#end-user-privacy-policy
- [ ] Clear description of what financial data you access and why
- [ ] Statement that you don't sell consumer financial data
- [ ] Data deletion rights
- [ ] Contact email for privacy inquiries

I can generate a privacy policy template for you.

---

### Q10: Does your organization obtain consent from consumers for data collection/processing/storage?

**Recommended answer: Yes**

```
Yes. We obtain explicit, informed consent from consumers at multiple points:

1. Registration: Users agree to our Terms of Service and Privacy Policy
   during account creation (checkbox consent with links to both documents).

2. Bank connection: Before initiating Plaid Link, we display a clear
   explanation of what data will be accessed and how it will be used.
   The user must actively initiate the connection by clicking "Connect
   Bank Account." Plaid's own consent flow within Plaid Link provides
   additional consumer disclosure.

3. Email connection: Separate, explicit consent is obtained before
   accessing email for order/receipt parsing, with clear explanation
   of what data is read and how it's used.

4. Data usage: Users can review what data has been collected in their
   account settings and can request deletion at any time.

Consent is recorded with timestamps in our database for audit purposes.
```

---

### Q11: Does your organization have a defined data deletion and retention policy?
*In compliance with applicable data privacy laws, reviewed periodically.*

**Recommended answer: Yes**

```
Yes. Our data retention and deletion policy defines:

Retention Schedule:
- Active account data: Retained while account is active, reviewed annually.
- Transaction history: Retained for the user's configured period (default
  3 years for tax purposes) or until user requests deletion.
- Plaid access tokens: Retained while bank connection is active. Immediately
  deleted (hard delete) when user disconnects a bank account.
- AI categorization data: Retained with associated transactions. Deleted
  when transactions are deleted.
- Session tokens: Auto-expire after inactivity period. Purged from database
  on regular schedule.

Deletion Procedures:
- Account deletion: Users can request full account deletion from settings.
  All personal data, financial data, Plaid tokens, and transaction history
  are permanently deleted within 30 days of request.
- Bank disconnection: Plaid access token is immediately deleted. Historical
  transaction data is retained per user preference (or deleted on request).
- Right to deletion: Users may request deletion of specific data or their
  entire account by contacting privacy@[yourdomain].app.

Compliance: This policy is reviewed annually and complies with CCPA
data deletion requirements.
```

⚠️ **ACTION REQUIRED:**
- [ ] Implement the account deletion endpoint in the app
- [ ] Implement Plaid token revocation (call Plaid's /item/remove endpoint)
- [ ] Write the actual Data Retention Policy document
- [ ] Add "Delete My Account" option in app settings

---

## Summary of Action Items Before Submitting

### Documents to Create:
- [ ] Information Security Policy (2-3 pages)
- [ ] Privacy Policy (publish at your domain/privacy)
- [ ] Terms of Service (publish at your domain/terms)
- [ ] Data Retention & Deletion Policy (internal document)

### Technical Setup Required:
- [ ] HTTPS with TLS 1.2+ enforced on production domain
- [ ] MFA on all cloud/hosting/GitHub/Plaid accounts
- [ ] Database encryption-at-rest (most managed DB providers do this)
- [ ] SSH key-only access to production
- [ ] GitHub Dependabot enabled
- [ ] Disk encryption on dev machines
- [ ] Implement account deletion API endpoint
- [ ] Implement Plaid /item/remove on bank disconnect

### Already Built Into the App: ✅
- [x] Plaid access tokens encrypted at-rest (AES-256-CBC)
- [x] Email OAuth tokens encrypted at-rest
- [x] MFA (TOTP) with Google Authenticator support
- [x] reCAPTCHA v3 on login/registration
- [x] Account lockout after failed attempts
- [x] Sanctum token authentication on all endpoints
- [x] Authorization policies (user-level data isolation)
- [x] Form Request validation on all inputs
- [x] CSRF protection on web routes
- [x] SQL injection prevention via Eloquent ORM
- [x] Password hashing via bcrypt
- [x] Rate limiting on auth endpoints
