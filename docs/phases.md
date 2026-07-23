# Ellipzo Development Phases and Claude Prompts

**Document version:** 1.0  
**Status:** Execution plan  
**Last updated:** 2026-07-23

## 1. How to Use This File

Build Ellipzo in order. Each phase has prerequisites, deliverables, exit criteria, and a copy-ready Claude prompt.

Do not ask Claude to build the entire platform in one prompt. Start a fresh focused session for each phase or large task. At the end of every session, Claude must update `docs/memory.md`.

### Mandatory prompt preamble

Add this at the beginning of every phase prompt:

```text
You are working on Ellipzo, a production-grade micro-earning and advertising platform.

Before changing anything:
1. Read docs/prd.md, docs/Architecture.md, docs/rules.md, docs/phases.md, docs/design.md, and docs/memory.md completely.
2. Inspect the repository, git status, installed versions, existing tests, and the current phase.
3. Follow the document priority defined in docs/rules.md.
4. Preserve unrelated work and do not use destructive commands.
5. Work only on the requested phase/task.

Do not use fake production data, placeholder logic, direct balance updates, or client-authoritative financial state. Do not enable real Bybit Pay or use production credentials. Nepal live crypto functionality must remain disabled unless written legal clearance is recorded.

Before coding, give me a concise implementation plan and list the files you expect to change. Then implement, test, and update docs/memory.md with exact results.
```

## 2. Phase Overview

| Phase | Name | Main outcome | Depends on |
| --- | --- | --- | --- |
| 0 | Product and compliance baseline | Approved requirements and launch gates | None |
| 1 | Foundation, design system, and identity | Working application shell and secure accounts | Phase 0 |
| 2 | Wallet and immutable ledger | Correct financial foundation | Phase 1 |
| 3 | Tasks, submissions, and moderation | End-to-end manual earning workflow | Phase 2 |
| 4 | Advertising and campaign budgets | End-to-end advertiser workflow | Phase 3 |
| 5 | Bybit Pay advertising deposits | Sandbox deposit and reconciliation | Phase 4 |
| 6 | Bybit Pay earning withdrawals | Sandbox hold, review, payout, reconciliation | Phase 5 |
| 7 | Disputes, referrals, support, notifications | Trust and engagement workflows | Phase 6 |
| 8 | Surveys and offerwalls | Provider-based earning expansion | Phase 7 |
| 9 | Operations, risk, and finance console | Complete staff operations | Phase 8 |
| 10 | Hardening, deployment, and launch review | Production candidate, not automatic launch | Phase 9 |

## 3. Phase 0 — Product and Compliance Baseline

### Goal

Turn the six planning files into an approved source of truth and resolve decisions that materially affect money, legality, and launch.

### Deliverables

- Review all six documents for contradictions.
- Confirm business entity and eligible adult operator for merchant onboarding.
- Confirm initial launch countries; Nepal remains disabled for live crypto.
- Confirm whether initial currency is USDT only.
- Confirm platform fee model.
- Confirm minimum/maximum deposit and withdrawal policy.
- Confirm task review window, dispute window, and payout review policy.
- Confirm advertiser refund/cancellation rules.
- Obtain or plan Bybit Pay merchant sandbox access.
- Create a decision log in `memory.md`.
- Produce an environment and credential checklist without real secrets.
- Define Phase 1 acceptance test list.

### Exit criteria

- No unresolved conflict across the six documents.
- Initial launch scope and disabled countries are explicit.
- Financial policy unknowns are recorded as blockers, not guessed.
- User approves the baseline.
- No production credential is stored.

### Claude prompt

```text
Execute Phase 0: Product and Compliance Baseline.

Audit the six Ellipzo documents for conflicts, missing financial rules, unclear launch-country assumptions, and missing Bybit Pay readiness requirements. Do not write application code yet.

Create or update:
- A concise decision table in docs/memory.md.
- An unresolved-decisions checklist.
- A sandbox/production environment variable checklist containing names only.
- Phase 1 acceptance tests.

Treat Nepal live cryptocurrency payments as disabled. Do not invent legal or merchant approval. Ask only the minimum blocking questions after completing every non-blocked documentation improvement. Finish by updating docs/memory.md.
```

## 4. Phase 1 — Foundation, Design System, and Identity

### Goal

Create the Laravel application foundation, responsive layouts, authentication, profile, country capability checks, and staff access shell.

### Deliverables

- Laravel 13 + PHP 8.3 project.
- React 19, TypeScript, Inertia 3, Tailwind 4, Vite.
- MySQL configuration and safe `.env.example`.
- Code formatting, linting, test, and CI commands.
- Public, authenticated, auth, and admin layouts.
- Design tokens from `design.md`.
- Email/password registration and login.
- Google sign-in through Socialite.
- Email verification and password reset.
- Secure sessions and logout.
- Profile including username, date of birth, country, locale, and timezone.
- Legal consent version records.
- Account and eligibility statuses.
- Country capabilities with deny-by-default payment flags.
- Staff roles/permissions and admin shell.
- Audit base for sensitive staff actions.
- Seed only safe roles, permissions, countries, and local development staff.

### Required tests

- Registration validation.
- Email verification gates.
- Google account link/create behavior.
- Session regeneration and logout.
- Profile ownership.
- Country capability denial.
- Normal user denied admin routes.
- Staff permission success/failure.
- No separate earner/advertiser role exists.

### Exit criteria

- Authentication journeys work using real database records.
- All layouts are responsive and accessible.
- Country/payment gates are server-enforced.
- Tests and formatting pass.
- `memory.md` is current.

### Claude prompt

```text
Execute Phase 1: Foundation, Design System, and Identity.

Initialize or complete the Laravel 13 modular monolith defined in docs/Architecture.md. Implement the design tokens and layouts from docs/design.md, then implement secure identity, Google login, email verification, password reset, profile, consent versioning, country capabilities, staff permissions, and an admin shell.

Important:
- A normal account can both earn and advertise; do not add earner/advertiser roles.
- Payment capabilities are denied by default and Nepal live crypto capabilities remain disabled.
- Do not add wallet balances yet.
- Keep controllers thin and add policies, Form Requests, migrations, factories, and tests.
- Use only the approved dependency set unless you first document and justify a change.

Run relevant tests and quality checks. Update docs/memory.md with exact files, migrations, commands, outcomes, and the next Phase 2 task.
```

## 5. Phase 2 — Wallet and Immutable Ledger

### Goal

Build the financial source of truth before any task reward, campaign spend, deposit, or withdrawal.

### Deliverables

- Currency configuration and Money value object.
- Atomic-unit parsing, formatting, overflow, and scale validation.
- Chart of accounts.
- Wallet accounts for earning, advertising, reserved advertising funds, withdrawal holds, platform/system accounts, and fees if approved.
- Ledger transactions and balanced entries.
- Central ledger-posting action.
- Cached balance strategy only if it remains transactionally consistent.
- Idempotency keys and business references.
- Compensating administrative adjustment flow.
- User transaction-history page.
- Read-only staff ledger explorer.
- Reconciliation command for ledger and cached balances.

### Required tests

- Money parsing and formatting.
- Extra-decimal and overflow rejection.
- Balanced and unbalanced transaction behavior.
- Ledger immutability.
- Duplicate business reference.
- Concurrent debit and reservation.
- Insufficient balance inside a lock.
- Administrative adjustment permission, reason, audit, and compensation.
- Transaction history ownership.

### Exit criteria

- Ledger invariant tests pass.
- No controller or user model directly mutates balances.
- Concurrent spending cannot create a negative available balance.
- Admin corrections create compensating entries.
- `memory.md` records the chart of accounts.

### Claude prompt

```text
Execute Phase 2: Wallet and Immutable Ledger.

First propose the exact chart of accounts and posting examples for:
1. Advertising deposit credit.
2. Campaign budget reservation and release.
3. Submission approval and platform fee.
4. Withdrawal hold, success, failure, and rejection.
5. Administrative correction.

Do not implement until the chart is internally balanced and consistent with docs/prd.md. Then implement atomic-unit Money handling, immutable balanced ledger transactions, wallet accounts, idempotency, locks, transaction history, staff read-only ledger view, reconciliation, and tests.

Never use floats or direct balance updates. Do not call Bybit Pay. Run concurrency and invariant tests, then update docs/memory.md.
```

## 6. Phase 3 — Tasks, Submissions, and Moderation

### Goal

Deliver a complete manual-task earning workflow backed by the ledger.

### Deliverables

- Categories and subcategories.
- Eligible task marketplace with filters and pagination.
- Task details and optional reservation/start.
- Configurable proof schema.
- Private proof attachments.
- Submission creation and status history.
- Staff campaign/task moderation needed for this phase.
- Review queue.
- Approval/rejection with standardized reason.
- Atomic reward settlement from a system-funded test campaign account or approved development funding method.
- User submission history.
- Report-task action.

### Required tests

- Country/device/user eligibility.
- Reservation expiry and quantity limit.
- Proof validation and private download authorization.
- Duplicate submission denial.
- Reviewer permission.
- User cannot review own submission.
- Approval and reward are atomic.
- Duplicate approval does not duplicate reward.
- Rejection makes no reward.
- Concurrent final-slot submissions cannot overspend quantity/budget.

### Exit criteria

- A verified user can complete the full manual-task sandbox journey.
- Every status transition is recorded.
- Proof is private.
- Reward settlement uses the ledger exactly once.
- All required tests pass.

### Claude prompt

```text
Execute Phase 3: Tasks, Submissions, and Moderation.

Implement the manual-task marketplace, eligibility, proof schema, private uploads, submissions, status history, staff moderation, and atomic reward settlement. Use centralized state transitions and the Phase 2 ledger.

Do not implement advertiser self-service funding or Bybit Pay yet. Use explicit development fixtures/factories for funded test campaigns, never a fake production balance. Include loading, empty, error, unavailable, pending, approved, and rejected states from docs/design.md.

Add authorization, validation, idempotency, concurrency, file privacy, and settlement tests. Run checks and update docs/memory.md.
```

## 7. Phase 4 — Advertising and Campaign Budgets

### Goal

Allow a normal user to fund a development advertising balance, create and manage campaigns, reserve budgets, and review submissions.

### Deliverables

- Advertise overview.
- My Funds, Advertising Funds, Reserved Funds, Actual Spend Today.
- Add Funds page shell that clearly says sandbox/unavailable until Phase 5.
- Campaign drafts and proof builder.
- Server-calculated reward, fee, quantity, and total budget.
- Budget reservation.
- Campaign moderation.
- Statuses: draft, pending approval, scheduled, running, paused, budget exceeded, completed, rejected, cancelled.
- Campaign list and analytics using real records.
- Advertiser submission review.
- Pause/resume/cancel and correct fund release.
- Material-edit remoderation.

### Required tests

- Same normal account can earn and advertise.
- Campaign cannot reserve more than available advertising funds.
- Concurrent reservations cannot overspend.
- Campaign owner cannot complete their own payable task.
- Unauthorized user cannot edit/review another campaign.
- Material edit requires remoderation.
- Pause/cancel/reject/completion release correct reservations.
- Submission approval spends correct campaign amount exactly once.
- Actual Spend Today comes from ledger data.

### Exit criteria

- End-to-end sandbox advertiser workflow works.
- Campaign budgets cannot overspend.
- All analytics come from actual records.
- No Bybit production calls exist.

### Claude prompt

```text
Execute Phase 4: Advertising and Campaign Budgets.

Implement the Advertise area for the same normal user account: overview, funding summary, campaigns, proof builder, server-side budget calculations, reservations, moderation, lifecycle actions, submission review, and real analytics.

Use the immutable ledger and row locks. The Add Funds view is a truthful unavailable/sandbox shell until Phase 5; do not simulate a successful payment. Campaign status transitions must be centralized and fully tested, including concurrency and release of reserved funds.

Run tests and quality checks, then update docs/memory.md.
```

## 8. Phase 5 — Bybit Pay Advertising Deposits

### Goal

Implement safe Bybit Pay sandbox deposits that credit advertising balance exactly once.

### Preconditions

- Phase 2 ledger and Phase 4 advertising balances are stable.
- Official current Bybit Pay docs have been reviewed.
- Sandbox merchant access is available.
- Merchant operation is handled by an eligible adult or legal entity.
- Live-country capability remains disabled.

### Deliverables

- Provider-independent payment contract.
- `BybitPayClient` and `FakeBybitPayClient`.
- Strict Bybit configuration validation.
- Deposit intents and attempts.
- One-time payment creation.
- Checkout/QR page with pending/expired/failed/paid states.
- Isolated webhook route.
- Raw-body signature and timestamp verification.
- Provider-event deduplication.
- Atomic advertising ledger credit.
- Payment-result query.
- Pending-deposit reconciliation.
- Sandbox status-mock test support where officially available.
- Staff deposit and exception views.

### Required tests

- Deposit intent eligibility and limits.
- No client return URL credit.
- Valid webhook credits once.
- Duplicate webhook credits once.
- Invalid signature and stale timestamp rejected.
- Merchant/reference/amount/currency mismatch enters review.
- Out-of-order event handled safely.
- Concurrent webhook/query finalization credits once.
- Provider timeout preserves a recoverable state.
- Nepal and unapproved country denied server-side.
- Sandbox and production configuration cannot mix.

### Exit criteria

- Sandbox deposit works end to end.
- Ledger credit is idempotent.
- Reconciliation detects mismatches.
- No production secret or production enablement exists.

### Claude prompt

```text
Execute Phase 5: Bybit Pay Advertising Deposits in sandbox only.

Before coding, read the current official Bybit Pay guide, authentication, create-payment, payment-result, payment-notify, signature, status, and changelog documentation. Record the documentation date and any difference from docs/Architecture.md in docs/memory.md.

Implement the provider contract, Bybit adapter, internal deposit intents, one-time checkout/QR flow, signed raw-body webhook verification, unique provider events, atomic one-time advertising-balance credit, provider query, reconciliation, staff exceptions, and complete tests.

Never credit from successUrl, frontend state, screenshot, or an unverified callback. Do not use production credentials or enable Nepal/live payments. Treat an unknown provider result as pending review, not success or safe retry. Run tests and update docs/memory.md.
```

## 9. Phase 6 — Bybit Pay Earning Withdrawals

### Goal

Implement safe sandbox withdrawals using holds, review, idempotent payout submission, signed final status, and reconciliation.

### Preconditions

- Phase 5 webhook verification and reconciliation are stable.
- Current official payout/payee requirements are reviewed.
- Financial limits, fees, review policy, and destination requirements are approved.

### Deliverables

- Payout destination capture and masked display.
- Eligibility, security, balance, velocity, and limit checks.
- Password/2FA confirmation.
- Withdrawal request and immutable hold.
- Manual risk/finance review.
- Approval/rejection.
- Idempotent payout job.
- Provider state and attempts.
- Signed final webhook handling.
- Provider query and reconciliation.
- Hold consumption or release.
- User withdrawal history and status timeline.
- Staff withdrawal and exception views.

### Required tests

- Insufficient earnings.
- Advertising funds cannot be withdrawn.
- Country/age/KYC/security denial.
- Concurrent withdrawal requests cannot overspend.
- Sensitive profile change cooldown.
- Only permitted staff can approve.
- Duplicate approval submits once.
- Network timeout after submission does not blindly retry.
- Valid success consumes hold once.
- Confirmed failure/rejection releases hold once.
- Duplicate/out-of-order webhook safety.
- Staff cannot manually fake paid status.

### Exit criteria

- Full sandbox payout journey works.
- Funds are never lost or duplicated across failures.
- Unknown states reconcile safely.
- Production activation remains blocked.

### Claude prompt

```text
Execute Phase 6: Bybit Pay Earning Withdrawals in sandbox only.

First verify current official Bybit Pay payout, payee, authentication, webhook, status, and reconciliation requirements. Then implement payout identity, eligibility and security checks, ledger holds, staff review, idempotent payout submission, provider state, signed webhook handling, query/reconciliation, hold consumption/release, histories, and tests.

Advertising funds must never be withdrawable. A timeout after sending a payout is UNKNOWN/REVIEW_REQUIRED and must not trigger an immediate retry. Do not enable production, use real credentials, or bypass country/age/KYC/provider restrictions.

Run financial, concurrency, duplicate, and failure tests. Update docs/memory.md.
```

## 10. Phase 7 — Disputes, Referrals, Support, and Notifications

### Goal

Add the trust, resolution, retention, and communication workflows needed around the core marketplace.

### Deliverables

- Submission dispute creation, advertiser response, escalation, and staff resolution.
- Immutable dispute timeline.
- Ledger settlement on overturned rejection or corrected approval.
- Referral codes and attribution.
- Eligible commission rules and anti-self-referral checks.
- Referral dashboard with referred username and eligible earnings/commission.
- Support tickets, replies, categories, attachments, linked records, and green “Create New Ticket” action.
- In-app notifications.
- Security and transactional email notifications.
- Unread notification dot only when unread items exist.
- User notification preferences.

### Required tests

- Dispute ownership and windows.
- Only valid rejected submission can be disputed.
- Final resolution settles once.
- Referral cannot self-attribute or be changed improperly.
- No commission on deposit/withdrawal/refund/fraud.
- Ticket privacy and staff permissions.
- Notification failure does not roll back the business event.
- Unread count is accurate.

### Exit criteria

- Dispute, referral, and support flows work end to end.
- Financial outcomes use the ledger.
- Notifications are useful and non-authoritative.

### Claude prompt

```text
Execute Phase 7: Disputes, Referrals, Support, and Notifications.

Implement the four modules using centralized state transitions and existing domain events. Dispute outcomes and referral commissions must post through the ledger exactly once. Support attachments must be private. The support area must include a prominent emerald/green “Create New Ticket” action. The notification red dot appears only for unread notifications and must not appear on public pages.

Add authorization, timing-window, idempotency, privacy, and notification-failure tests. Run checks and update docs/memory.md.
```

## 11. Phase 8 — Surveys and Offerwalls

### Goal

Expand earning opportunities through provider adapters without weakening eligibility, callback security, ledger integrity, or chargeback handling.

### Deliverables

- Survey provider contract and adapter structure.
- Offerwall provider contract and adapter structure.
- Staff-managed provider configuration.
- Eligibility and provider availability.
- Signed callback verification where supported.
- Unique provider transaction events.
- Pending/confirmed/chargeback states.
- Idempotent reward credit.
- Chargeback through compensating ledger entries.
- User histories.
- Provider health and outage state.

### Required tests

- Valid/invalid callback.
- Duplicate provider transaction.
- User/country mismatch.
- Pending-to-confirmed transition.
- Confirmed reward credits once.
- Chargeback compensates once.
- Provider outage state.
- Secret redaction.

### Exit criteria

- At least one approved sandbox provider adapter passes end-to-end tests.
- Duplicate and chargeback paths are safe.
- Provider-specific code is isolated.

### Claude prompt

```text
Execute Phase 8: Surveys and Offerwalls.

Implement provider-independent survey and offerwall contracts, event models, callback verification boundaries, reward state machines, pending periods, ledger credit, compensating chargebacks, histories, staff configuration, provider health, and tests.

Do not invent a provider API. If provider documentation or credentials are unavailable, complete the contracts, fake adapters, UI, and contract tests, then record the real integration as blocked. Never treat a client redirect as reward confirmation. Update docs/memory.md.
```

## 12. Phase 9 — Operations, Risk, and Finance Console

### Goal

Complete the real staff workflows required to operate Ellipzo safely.

### Deliverables

- Operational dashboard using real queries.
- User limits, suspension, risk signals, and history.
- Campaign/submission/dispute queues.
- Deposit/withdrawal/reconciliation queues.
- Ledger adjustments through compensation only.
- Country, limits, fee, and feature-flag configuration.
- Staff-role management with least privilege.
- Complete append-only audit views.
- Reports and controlled exports.
- Queue/provider health.
- Risk rules and manual review reasons.
- Incident and reconciliation runbooks in documentation.

### Required tests

- Permission matrix.
- Sensitive actions require reason/confirmation.
- Audit records created.
- Finance operator cannot manage staff.
- Support agent cannot adjust balances.
- Normal user cannot access admin routes.
- Export authorization and redaction.
- Dashboard values match source records.

### Exit criteria

- Staff can operate all in-scope workflows without database access.
- Least privilege and audit coverage are verified.
- No dashboard metric is fabricated.

### Claude prompt

```text
Execute Phase 9: Operations, Risk, and Finance Console.

Build the complete staff console over the existing domain actions. Do not create admin-only shortcuts that bypass policies, transitions, ledger, idempotency, or audit. Implement real dashboard queries, queues, configuration, risk review, staff permissions, audit views, controlled exports, and operational health.

Create and test a permission matrix. Add concise incident and reconciliation runbooks. Run all admin, financial, and authorization tests, then update docs/memory.md.
```

## 13. Phase 10 — Hardening, Deployment, and Launch Review

### Goal

Produce a secure release candidate and evidence-based launch decision. This phase does not automatically authorize production payments.

### Deliverables

- Full test suite and critical browser journeys.
- Load and concurrency tests for task claims, budget reservations, webhooks, and withdrawals.
- Security review against OWASP risks.
- Dependency and secret scanning.
- Upload and authorization review.
- Accessibility audit.
- Performance and query review.
- Backup and restore test.
- Queue and scheduler verification.
- Log redaction review.
- Monitoring and alerting.
- Staging deployment.
- Hostinger compatibility verification.
- Production runbook and rollback plan.
- Legal, merchant, country, and adult-operator evidence checklist.
- Final go/no-go report.

### Exit criteria

- No known critical/high security defect.
- Ledger reconciliation difference is zero in tests and staging.
- Duplicate/concurrent payment tests pass.
- Backup restore is proven.
- Monitoring and operational ownership exist.
- Bybit merchant production approval is evidenced.
- Each enabled country has legal approval.
- Nepal live crypto remains disabled unless its legal status has changed and written approval exists.
- A human explicitly authorizes production deployment and payment activation.

### Claude prompt

```text
Execute Phase 10: Hardening, Deployment, and Launch Review.

Do not activate production payments. Audit and test the completed Ellipzo application: security, authorization, ledger invariants, concurrency, webhook replay/idempotency, payout unknown states, uploads, privacy, accessibility, performance, queue/scheduler behavior, backups, restore, monitoring, deployment, and rollback.

Deploy only to an approved staging environment if explicitly authorized. Produce an evidence-based go/no-go report with blocking issues. Merchant approval and launch-country legal approval must be documented; do not infer them. Keep Nepal live crypto disabled unless written clearance exists.

Run every available check, record exact results, and update docs/memory.md.
```

## 14. Small-Task Prompt Template

Use this after a phase has started:

```text
Continue Ellipzo Phase [NUMBER], task: [EXACT TASK].

Read all six docs first, especially the current state in docs/memory.md. Inspect existing code and tests. Work only on this task.

Acceptance criteria:
- [criterion 1]
- [criterion 2]
- [criterion 3]

Required:
- Server-side validation and authorization.
- Correct loading, empty, validation, forbidden, error, and success states.
- No direct balance updates or client-authoritative money.
- Relevant automated tests.
- Formatting/static checks.
- Update docs/memory.md with exact results and next task.

Do not claim completion if any acceptance criterion or test remains unresolved.
```

## 15. Bug-Fix Prompt Template

```text
Diagnose and fix this Ellipzo bug:

[PASTE ERROR, REPRODUCTION STEPS, AND EXPECTED BEHAVIOR]

Before editing, read all six docs and inspect the related code, logs, migration state, and tests. Reproduce or identify the failing path. Explain the root cause briefly, then implement the smallest correct fix.

Add a regression test. Do not weaken security, authorization, ledger invariants, validation, idempotency, or existing tests. Run the focused test and relevant regression suite. Update docs/memory.md with the root cause, changed files, tests, and result.
```

## 16. Review Prompt Template

```text
Review the current Ellipzo Phase [NUMBER] implementation without changing code first.

Read all six docs and compare the implementation against the phase deliverables and exit criteria. Inspect migrations, policies, actions, state transitions, financial paths, UI states, and tests.

Report findings in severity order:
- Critical
- High
- Medium
- Low

For each finding include evidence, impact, and the smallest recommended fix. Pay special attention to direct balance mutations, float usage, missing locks, authorization gaps, duplicate webhooks, unsafe retries, exposed secrets, fake metrics, and untested failure paths.

After the report, wait for approval before making changes unless the user explicitly asked you to fix all findings.
```
