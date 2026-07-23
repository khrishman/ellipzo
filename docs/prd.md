# Ellipzo Product Requirements Document

**Document version:** 1.0  
**Status:** Planning baseline  
**Last updated:** 2026-07-23  
**Product domain:** `ellipzo.com`

## 1. Product Summary

Ellipzo is a production-grade micro-earning and advertising platform. Eligible users complete legitimate digital tasks, surveys, and offerwall activities to earn rewards. The same account can also fund and run advertising campaigns.

Ellipzo is not an investment, trading, staking, betting, gambling, or get-rich-quick platform. Users earn only after completing work that passes the required review.

Bybit Pay is the primary planned payment rail:

- Advertisers deposit funds through Bybit Pay QR checkout.
- Eligible earners request withdrawals through Bybit Pay merchant payouts.
- Payment capabilities remain disabled by country and environment until the relevant merchant approval, provider eligibility, legal review, and operational controls are complete.

## 2. Product Principles

1. **One account, two capabilities.** A normal user can earn and advertise without switching account types.
2. **Real work, real review.** Rewards are tied to completed tasks, approved survey/offerwall events, or validated submissions.
3. **Ledger before balance.** Every balance change must come from an immutable financial ledger entry.
4. **Server authority.** The server decides permissions, balances, pricing, rewards, campaign spend, and payment status.
5. **Payment safety.** Redirect pages and client-side success messages never credit money. Verified provider data does.
6. **Mobile-first earning.** Task discovery and submission must work especially well on phones.
7. **Dashboard-first advertising.** Campaign creation, funding, reviews, and analytics should be efficient on larger screens while remaining responsive.
8. **No demo logic in production paths.** Features must use real database records, validation, permissions, and error states.
9. **Compliance by configuration.** Country availability, age eligibility, KYC requirements, payout limits, and payment methods must be configurable and enforceable.
10. **Clear user communication.** Statuses, deductions, holds, rejections, and failures must be explained in plain language.

## 3. Launch and Compliance Gate

The codebase may be developed and tested in sandbox mode, but live money movement must not be enabled merely because the code is complete.

Before enabling Bybit Pay in any country, Ellipzo must have:

- An approved Bybit Pay merchant account held by an eligible adult or legal entity.
- Written confirmation that the business model and transaction types are accepted by Bybit.
- A legal review for the operating entity and every launch country.
- Terms of Service, Privacy Policy, Refund Policy, Acceptable Use Policy, and payment disclosures.
- Age, country, sanctions, KYC/KYB, AML/CFT, tax, and data-retention controls appropriate to the jurisdiction.
- A documented incident, reconciliation, and customer-support process.

### Nepal restriction

Nepal is a desired future market, but current Nepal Rastra Bank publications state that cryptocurrency transactions and cryptocurrency-related business are illegal in Nepal. Therefore:

- Do not enable live crypto deposits, withdrawals, or crypto-denominated campaigns for users located in Nepal.
- Do not market live crypto payment services to Nepal.
- Nepal may be used only for non-transactional product development and sandbox testing until written legal clearance confirms launch is permitted.
- Country access must be controlled by server-side policy, not only by a hidden button.
- Do not provide or implement evasion methods such as VPN guidance, false country selection, or KYC bypasses.

This is a product safety requirement, not legal advice. Qualified counsel must approve each launch jurisdiction.

Reference baseline checked on 2026-07-23:

- [Nepal Rastra Bank cryptocurrency FAQ](https://www.nrb.org.np/2020/11/%E0%A4%A8%E0%A5%87%E0%A4%AA%E0%A4%BE%E0%A4%B2%E0%A4%AE%E0%A4%BE-bitcoin-%E0%A4%B2%E0%A4%97%E0%A4%BE%E0%A4%AF%E0%A4%A4%E0%A4%95%E0%A4%BE-cryptocurrency-%E0%A4%B9%E0%A4%B0%E0%A5%81%E0%A4%95%E0%A5%8B/)
- [FIU-Nepal Strategic Analysis Report 2025 on Virtual Assets](https://www.nrb.org.np/contents/uploads/2026/01/FIU-Nepal-Strategic-Analysis-Report-2025-on-VA.pdf)

Re-check official sources and obtain qualified advice before making a launch decision.

## 4. Goals

### 4.1 User goals

- Find suitable earning opportunities quickly.
- Understand requirements and expected reward before starting.
- Submit evidence from a mobile device.
- Track pending, approved, rejected, disputed, and paid work.
- See an accurate available balance, held balance, and transaction history.
- Request a withdrawal through an approved method where eligible.
- Deposit advertising funds and create a campaign from the same account.
- Review campaign submissions and manage spend.
- Receive useful notifications and get support when something goes wrong.

### 4.2 Business goals

- Build a trustworthy two-sided marketplace for digital tasks.
- Prevent advertisers from overspending their available advertising balance.
- Prevent duplicate rewards, duplicate deposits, and duplicate payouts.
- Give staff complete, audited operational control.
- Support multiple countries through configurable policies.
- Add surveys and offerwalls without weakening ledger or fraud controls.
- Begin on infrastructure compatible with the chosen hosting environment and retain a clear path to scale.

## 5. Non-Goals

Ellipzo will not:

- Accept investments or promise financial returns.
- Offer trading, leverage, derivatives, staking, lending, betting, gambling, or games of chance.
- Hold user funds without a recorded business purpose and ledger trail.
- Allow anonymous withdrawals.
- Credit deposits solely from a browser redirect.
- Allow advertisers to create prohibited, deceptive, illegal, or harmful tasks.
- Allow tasks involving fake reviews, fake engagement, account renting, credential sharing, identity fraud, harassment, malware, piracy, or circumvention of another platform's rules.
- Launch live Bybit Pay flows without approved merchant credentials and jurisdiction clearance.
- Use fabricated analytics or placeholder financial data in production.

## 6. Target Users

### 6.1 Earners

Adults who are legally eligible in an enabled country and want to earn modest rewards by completing legitimate online work.

Needs:

- Clear tasks and fair requirements.
- Mobile-friendly proof submission.
- Predictable review statuses.
- Transparent earnings and withdrawals.
- A dispute channel for rejected work.

### 6.2 Advertisers

Individuals or organizations legally eligible to buy legitimate digital work.

Needs:

- Simple campaign funding.
- Budget and cost controls.
- Geographic and quantity targeting.
- Submission review.
- Clear spend and remaining-budget reporting.
- Dispute handling and audit history.

### 6.3 Staff

#### Administrator

Has platform-wide control over users, campaigns, submissions, payments, support, policies, configuration, and staff permissions. Sensitive actions require confirmation, a reason, and an audit record.

#### Moderator

Reviews campaigns, submissions, proof, disputes, and policy violations within assigned permissions.

#### Finance operator

Reviews deposits, withdrawals, reconciliation exceptions, refunds, and ledger adjustments within assigned permissions.

#### Support agent

Handles tickets and can view only the user and transaction information needed for support.

There are no separate earner and advertiser account roles. Staff roles are separate and permission-based.

## 7. Core User Journeys

### 7.1 Registration and onboarding

1. User registers with email and password or Google.
2. User verifies their email.
3. User accepts current legal documents and confirms age/country eligibility.
4. The server resolves country policy and available features.
5. User completes required profile and security steps.
6. User enters the normal dashboard and can access both Earn and Advertise areas if permitted.

### 7.2 Complete a manual task

1. User browses eligible tasks.
2. User opens task details and sees reward, instructions, proof requirements, time limit, availability, and policy warnings.
3. User starts or reserves the task when required.
4. User submits structured proof and allowed attachments.
5. Submission enters `PENDING_REVIEW`.
6. The authorized reviewer approves or rejects it with a reason.
7. Approval creates the required campaign-spend and earner-reward ledger entries atomically.
8. Rejection can expose a dispute action when the campaign policy permits.

### 7.3 Fund advertising balance through Bybit Pay

1. Eligible advertiser enters an amount within configured limits.
2. Server creates an internal deposit intent with a unique merchant trade number.
3. Server requests a Bybit Pay one-time payment order.
4. User receives the hosted checkout link or QR content.
5. Ellipzo waits for a signed Bybit Pay webhook and may query the provider for reconciliation.
6. On a verified successful payment, Ellipzo credits the advertising balance exactly once through the ledger.
7. Failed, expired, mismatched, duplicate, or unverified events do not credit the balance.

### 7.4 Create and run a campaign

1. Advertiser opens the Advertise area.
2. Advertiser sees Advertising Funds, Reserved Funds, Actual Spend Today, and recent campaigns.
3. Advertiser creates a campaign with category, title, description, proof schema, reward per completion, quantity, total budget, targeting, timing, and review rules.
4. Server calculates the required reservation and validates sufficient available advertising balance.
5. Submitted campaign enters moderation.
6. Approved campaign becomes scheduled or running.
7. The reserved budget prevents overspending.
8. Campaign status can be `DRAFT`, `PENDING_APPROVAL`, `SCHEDULED`, `RUNNING`, `PAUSED`, `BUDGET_EXCEEDED`, `COMPLETED`, `REJECTED`, or `CANCELLED`.

### 7.5 Withdraw earnings through Bybit Pay

1. Eligible user adds and verifies the required Bybit payout identity, subject to provider rules.
2. User enters a withdrawal amount and confirms with password and, when enabled, two-factor authentication.
3. Server checks country, age, KYC, available earnings, limits, account risk, duplicate request, and payout configuration.
4. The amount moves from available earnings to a withdrawal hold through the ledger.
5. Request enters manual or automated risk review.
6. Approved request is sent once to Bybit Pay using a unique merchant trade number.
7. A signed webhook or provider query confirms the final state.
8. Success consumes the hold. Failure releases it. Unknown results remain pending for reconciliation and are never guessed.

### 7.6 Dispute a rejected submission

1. Earner opens a rejected submission and sees the advertiser's reason.
2. Earner submits a dispute explanation and any additional allowed evidence.
3. Advertiser may accept the dispute or uphold the rejection within the configured window.
4. Escalated or expired disputes go to staff.
5. Final resolution and resulting ledger entries are atomic and audited.

## 8. Functional Requirements

### 8.1 Public website

- Landing page explaining tasks, surveys, offerwalls, and advertising.
- Clear “not an investment platform” language.
- How It Works for earners and advertisers.
- FAQ and Help Center.
- Terms, Privacy, Acceptable Use, Refund, Cookie, and payment disclosures.
- Country-availability notice.
- No logged-in notification bell on public pages.
- SEO metadata, social preview metadata, sitemap, and robots controls.

### 8.2 Authentication and account security

- Email/password registration and login.
- Google sign-in.
- Email verification.
- Password reset.
- Persistent secure sessions and logout from the current or all devices.
- Password confirmation for sensitive actions.
- Optional two-factor authentication, required before withdrawals when configured.
- Login, reset, verification, and sensitive-action rate limits.
- Session list with revocation.
- Account status: `ACTIVE`, `LIMITED`, `SUSPENDED`, `CLOSED`.
- Staff impersonation is prohibited unless a later audited support design explicitly adds it.

### 8.3 User profile and eligibility

- Display name, username, email, date of birth, country, locale, and timezone.
- Country initially detected as a hint; the server must not treat IP detection as proof.
- Legal-document consent records with version and timestamp.
- Eligibility status and missing requirements.
- Payout identity stored securely and masked in the UI.
- Data export and account-closure request.

### 8.4 User dashboard

- Available earnings.
- Earnings on hold.
- Advertising funds.
- Reserved advertising funds.
- Pending submissions.
- Recent transactions.
- Recommended eligible tasks.
- Campaign snapshot for users who advertise.
- New-notification indicator only when unread notifications exist.

### 8.5 Task marketplace

- Categories and subcategories managed by staff.
- Search, sorting, and filters for category, reward, country, device, proof type, and availability.
- Eligibility rules enforced on the server.
- Task detail page with complete instructions and proof requirements.
- Optional reservation/start flow and deadline.
- Per-user completion limits.
- Campaign-wide quantity and budget limits.
- Hide or mark unavailable tasks without leaking restricted data.
- Task report action.

### 8.6 Submission and proof

- Structured text fields, URLs, choices, and allowed image/document attachments.
- Private file storage with authorization checks.
- Server-side type, size, extension, and content validation.
- No executable files, HTML uploads, or SVG proof uploads.
- Status history with timestamps and actor.
- Review notes and standardized rejection reasons.
- Review deadline and escalation rules.
- Duplicate-proof and duplicate-link signals.
- Approval and financial settlement in one database transaction.

### 8.7 Advertising

- Advertising overview with My Funds, Advertising Funds, Reserved Funds, Actual Spend Today, and Add Funds.
- Campaign list grouped or filtered by status.
- Create, edit draft, preview, submit, pause, resume, and cancel where valid.
- Campaign moderation before first activation and after material changes.
- Reward, quantity, fees, and total reservation calculated on the server.
- Country/device targeting.
- Start/end dates.
- Proof schema builder using approved field types.
- Submission review queue.
- Reject action requires a reason.
- Dispute response actions.
- Campaign analytics based on real records.
- Budget release rules for cancelled, rejected, completed, or expired campaigns.

### 8.8 Wallet and ledger

Ellipzo has two user-facing balance purposes:

- **Earning balance:** rewards available or held for withdrawal.
- **Advertising balance:** funds available or reserved for campaigns.

Requirements:

- Double-entry or equivalently balanced immutable ledger.
- No direct balance mutation from controllers, seeders, or admin forms.
- Each ledger transaction has a unique reference, type, actor, description, currency, timestamp, and related entity.
- Amounts use integer atomic units based on the configured currency scale. Never use binary floating point.
- Ledger entries cannot be edited or deleted.
- Corrections use compensating entries.
- Cached balance columns, if used, are updated in the same transaction and regularly reconciled.
- User-visible transaction history with filters and status.
- Administrative adjustments require permission, reason, confirmation, and audit log.
- Database locks and idempotency prevent overspend and duplicate credit.

### 8.9 Bybit Pay deposits

- Primary use: fund advertising balance.
- One-time QR/hosted checkout, not recurring payment, for initial release.
- Official sandbox must be used before production.
- Credentials are server-only secrets.
- Internal deposit is created before the provider request.
- Unique `merchantTradeNo` and provider `payId` are stored.
- Amount, currency, merchant ID, order identity, and status are verified.
- Webhook signature is verified against the raw request body before JSON processing.
- Webhook timestamp freshness and replay protection are enforced.
- Webhook processing is idempotent.
- Browser return URLs are informational only.
- Provider status query is used for pending-order reconciliation.
- Deposit statuses: `CREATED`, `PROVIDER_PENDING`, `PAID`, `FAILED`, `EXPIRED`, `REFUNDED`, `REVIEW_REQUIRED`.
- Only the final verified success transition creates a ledger credit.
- Refunds are a staff-controlled later capability and must create compensating ledger entries.

### 8.10 Bybit Pay withdrawals

- Primary use: withdraw eligible earning balance through merchant payout.
- Only enabled for eligible adults in approved jurisdictions and under an approved merchant account.
- Withdrawal destination must follow the current provider payee requirements.
- Limits and fees are configuration-driven and shown before confirmation.
- Hold funds before review or provider submission.
- Unique internal request ID and `merchantTradeNo`.
- Payout submission must be idempotent and never retried blindly after an unknown response.
- Withdrawal statuses: `REQUESTED`, `HELD`, `UNDER_REVIEW`, `APPROVED`, `PROVIDER_PENDING`, `PAID`, `FAILED`, `REJECTED`, `CANCELLED`, `REVIEW_REQUIRED`.
- Signed webhook plus provider query/reconciliation determines final state.
- Final success consumes the hold; confirmed failure or rejection releases it.
- Staff cannot mark a payout paid without an approved, audited reconciliation procedure.
- High-risk or unusual requests require manual review.

### 8.11 Surveys and offerwalls

- Provider adapter interface rather than provider-specific logic in controllers.
- Provider configuration and credentials are server-only.
- Signed callback verification where supported.
- Unique provider transaction IDs.
- Idempotent reward handling.
- Configurable pending/chargeback period.
- Chargebacks use compensating ledger entries and can limit an account.
- Country/device eligibility.
- Provider outage state.
- These modules may launch after the manual-task and ledger foundations are stable.

### 8.12 Referrals

- Unique referral code/link.
- Referral relationship is immutable after the allowed attribution window.
- Dashboard shows referred username, join date, eligible earnings, and commission.
- Commission rates are configuration-driven.
- No commission on deposits, withdrawals, transfers, refunds, or fraudulent activity.
- Commission is created only from eligible, settled earning events.
- Self-referral and referral rings generate risk signals.

### 8.13 Notifications

- In-app notification center.
- Email for security events, campaign decisions, disputes, deposits, and withdrawals.
- User preference controls for non-essential messages.
- Unread red dot only when unread notifications exist.
- Notification delivery failures do not roll back the underlying business event.

### 8.14 Support tickets

- Prominent green “Create New Ticket” action.
- Categories, priority, subject, message, and attachments.
- Threaded replies.
- Statuses: `OPEN`, `AWAITING_USER`, `AWAITING_STAFF`, `RESOLVED`, `CLOSED`.
- User can see only their own tickets.
- Staff access is permission-based and audited.
- Ticket links to relevant transaction, campaign, submission, or dispute when authorized.

### 8.15 Admin and staff console

- Real operational dashboard with no fabricated metrics.
- Manage users, statuses, eligibility, risk flags, and sessions.
- Manage staff roles and permissions.
- Manage categories and subcategories.
- Moderate campaigns and material edits.
- Review submissions and disputes.
- Review deposits, withdrawals, refunds, and reconciliation exceptions.
- Add or remove earning or advertising funds only through compensating ledger transactions with a reason.
- Manage payment methods, country policies, limits, fees, and feature flags.
- Manage surveys, offerwalls, providers, and callback health.
- Manage support tickets, reports, and announcements.
- View append-only audit events.
- Export authorized operational reports.
- Dangerous actions require explicit confirmation and cannot be bulk-triggered accidentally.

### 8.16 Fraud and abuse controls

- Rate limits and bot protection on high-risk endpoints.
- Server-side country and eligibility policy.
- Account, device, IP, proof, destination, and behavior signals.
- Duplicate account and self-referral signals.
- Velocity limits for registrations, submissions, deposits, and withdrawals.
- Withdrawal cooldown after sensitive account changes when configured.
- Manual review queue with reason codes.
- Risk signals support decisions; they do not silently confiscate funds.
- Staff actions and overrides are audited.

## 9. Business Rules

- A user can earn and advertise from the same normal account.
- Advertising campaigns can spend only from available advertising balance.
- Pending campaign budgets are reserved before activation.
- A task cannot approve more payable submissions than its funded quantity.
- A user cannot approve their own submission.
- A campaign owner cannot complete their own campaign unless a future policy explicitly permits a non-rewarding preview.
- A rejected submission earns nothing unless the rejection is overturned.
- Withdrawal amounts can come only from available earning balance.
- Deposits fund advertising balance by default.
- Financial events are idempotent.
- Database and provider timestamps are stored in UTC; user interfaces display the user's timezone.
- All money values have currency and scale.
- Deleting a user never deletes required financial or audit records.
- Live payments are denied by default for unapproved countries.

## 10. Status and State-Machine Requirements

- Every important status transition is defined and validated centrally.
- Invalid backward or skipped transitions are rejected.
- Each transition records time, actor, reason, and source.
- Provider webhooks may arrive late, duplicated, or out of order; handlers must remain correct.
- Unknown provider results stay pending and enter reconciliation.
- UI labels are derived from backend state and do not invent state locally.

## 11. Reporting and Metrics

Product metrics:

- Verified registrations and onboarding completion.
- Task view-to-start and start-to-approved conversion.
- Submission approval, rejection, and dispute rates.
- Time to review.
- Active advertisers and active campaigns.
- Funded budget, reserved budget, actual spend, and released budget.
- Deposit success and reconciliation rates.
- Withdrawal processing time and failure rate.
- Support first response and resolution time.

Financial control metrics:

- Ledger imbalance count must always be zero.
- Cached-balance reconciliation differences.
- Duplicate webhook events safely ignored.
- Provider-to-internal transaction mismatches.
- Funds held in pending withdrawals.
- Campaign reservation versus remaining payable exposure.

## 12. Non-Functional Requirements

### Security

- Follow OWASP application security practices.
- Encrypt secrets and sensitive stored values appropriately.
- Use HTTPS in all non-local environments.
- CSRF protection for browser sessions.
- Secure, HTTP-only, same-site cookies.
- Authorization on every protected operation.
- Strict upload validation and private storage.
- Dependency and secret scanning in CI.

### Reliability

- Database transactions for all multi-record financial operations.
- Idempotent webhooks, callbacks, and payout submission.
- Retries only for operations proven safe to retry.
- Structured logs with correlation IDs and no secrets.
- Automated reconciliation.
- Daily backups and tested restore procedure before launch.

### Performance

- Paginate all unbounded lists.
- Index common filters and foreign keys.
- Avoid N+1 queries.
- Queue slow notifications, exports, and provider follow-up work.
- Optimize task browsing for mobile networks.

### Accessibility

- Target WCAG 2.2 AA.
- Keyboard-accessible controls.
- Visible focus states.
- Semantic labels and error associations.
- Sufficient color contrast.
- Do not communicate status by color alone.

### Privacy

- Collect only data needed for operations, compliance, risk, and support.
- Restrict sensitive data by permission.
- Define retention periods.
- Support lawful access, correction, export, and deletion requests while preserving mandatory financial records.

## 13. Acceptance Criteria for Production Readiness

Ellipzo is not production-ready until:

- All in-scope user journeys work with real database records.
- Authentication, authorization, verification, and sensitive-action confirmation are tested.
- Ledger invariants and concurrent spending tests pass.
- Duplicate, delayed, and out-of-order webhook tests pass.
- Deposit and withdrawal sandbox flows pass end to end.
- Reconciliation can detect and surface mismatches.
- No production secret is committed or exposed to the browser.
- Staff permissions and audit logs are verified.
- File uploads are private and authorization-tested.
- Country and age gates are enforced on the server.
- Legal documents and launch-country approval are complete.
- Bybit Pay merchant production approval is confirmed.
- Backups, restore, monitoring, and incident procedures have been tested.
- Critical and high-severity security findings are resolved.
- `memory.md` accurately records the implemented state and remaining blockers.

## 14. Source of Truth

When documents conflict, use this priority:

1. `rules.md` for safety, coding, and AI boundaries.
2. `prd.md` for product requirements and business rules.
3. `Architecture.md` for technical implementation.
4. `design.md` for interface and visual decisions.
5. `phases.md` for execution order.
6. `memory.md` for current progress, not intended requirements.

Do not silently resolve a material conflict. Record it in `memory.md` and ask for a decision.
