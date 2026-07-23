# Ellipzo Development Rules for Claude

**Document version:** 1.0  
**Status:** Mandatory instructions  
**Last updated:** 2026-07-23

## 1. Purpose

These rules govern every AI-assisted development session for Ellipzo. They apply to Claude and to any human or AI modifying the project.

The goal is a secure, production-grade product. Passing visual inspection is not enough. A feature is incomplete until its validation, authorization, persistence, failure states, tests, and documentation are complete.

## 2. Instruction Priority

When instructions conflict, follow this order:

1. Safety, law, provider terms, and explicit user instructions.
2. `rules.md`.
3. `prd.md`.
4. `Architecture.md`.
5. `design.md`.
6. `phases.md`.
7. `memory.md`.
8. Existing code conventions, if they do not conflict with the above.

Do not silently guess when the conflict affects money, security, legal eligibility, data loss, or architecture. Stop, record the conflict in `memory.md`, and ask one focused question.

## 3. Required Session Startup

At the beginning of every coding session:

1. Read all six files in `/docs` completely:
   - `prd.md`
   - `Architecture.md`
   - `rules.md`
   - `phases.md`
   - `design.md`
   - `memory.md`
2. Inspect the repository status and existing implementation.
3. Confirm the current phase and task from `memory.md`.
4. Check installed dependency versions before writing version-specific code.
5. State the narrow implementation goal and expected files.
6. Identify relevant tests before editing.
7. Do not work on a later phase unless its prerequisite exit criteria are complete or the user explicitly changes the plan.

## 4. Required Session Completion

Before declaring a task complete:

1. Run the smallest relevant automated tests.
2. Run formatting and static checks for changed code.
3. Run broader regression tests when shared or financial code changed.
4. Review authorization, validation, error, loading, empty, and retry states.
5. Confirm no secrets or sensitive data were added.
6. Update `memory.md` with:
   - Date and session summary.
   - Phase and task status.
   - Exact files created or changed.
   - Database migrations added.
   - Tests run and results.
   - Decisions made.
   - Known blockers or risks.
   - Exact next task and recommended next file.
7. Do not write “complete” if tests fail or required acceptance criteria remain.

## 5. Core Product Rules

- One normal account can both earn and advertise.
- Do not create separate `earner` and `advertiser` roles.
- Staff roles and permissions are separate.
- Ellipzo is not an investment, trading, staking, betting, gambling, or yield product.
- Deposits fund advertising balance by default.
- Withdrawals come from eligible available earning balance.
- Bybit Pay is the primary planned deposit and withdrawal provider.
- No live payment capability is enabled without merchant approval and country clearance.
- Nepal live crypto payment functionality stays disabled until written legal clearance.
- Do not implement country, age, KYC, sanctions, or provider-restriction bypasses.
- The service and Bybit Pay flows are restricted to eligible adults in approved jurisdictions.
- The merchant account must be held and operated by an eligible adult or legal entity.

## 6. Production-Grade Rule

Do not produce:

- Fake dashboards.
- Static counts presented as real.
- Buttons with no action.
- Routes that return placeholder success.
- Hardcoded user IDs, balances, or payment statuses.
- Mock payment success in a production code path.
- In-memory storage for persistent product data.
- Commented-out required validation.
- `TODO` in a feature claimed to be complete.
- Seed data containing real credentials.
- A “temporary” direct balance update.

Sandbox fakes are allowed only behind explicit interfaces and environment guards. A fake provider must be impossible to enable accidentally in production.

## 7. Scope Discipline

- Implement one phase or bounded task at a time.
- Do not redesign unrelated modules.
- Do not rename public routes, database columns, or domain concepts without checking references and recording the decision.
- Do not add speculative features outside the PRD.
- Do not add an AI/ML feature merely because an AI is writing the code.
- Prefer the simplest architecture that preserves financial correctness and testability.
- Do not split into microservices.
- Do not create a mobile app before the responsive web product is stable.

## 8. Code Quality

### PHP

- Use strict types in new PHP files where compatible.
- Follow PSR-12 and Laravel conventions.
- Use typed properties, parameters, and return values.
- Use PHP backed enums for important states.
- Use Form Requests for non-trivial request validation.
- Use policies/gates for resource authorization.
- Use actions for use cases and services for reusable domain/infrastructure behavior.
- Keep controllers thin.
- Use constructor dependency injection.
- Avoid service-location calls in domain code.
- Avoid static helpers for mutable business behavior.
- Use database transactions around multi-record state changes.
- Use explicit row locks for competing balance, reservation, or payout operations.

### TypeScript and React

- TypeScript strict mode stays enabled.
- Avoid `any`; use `unknown` and narrow it when input is not trusted.
- Use Inertia page props with explicit types.
- Keep server data authoritative.
- Reusable UI belongs in components; domain workflows belong in domain components/pages.
- Do not mirror backend state machines with conflicting frontend-only logic.
- Never perform financial arithmetic with JavaScript `number`.
- Accessibility is required for custom interactions.

### Naming

- Use product language from the PRD.
- Names describe intent: `ApproveSubmission`, not `ProcessData`.
- Boolean names start with `is`, `has`, `can`, or `should`.
- Avoid abbreviations except widely recognized domain names such as `API`, `URL`, `UID`, and `KYC`.

## 9. Architecture Boundaries

- Controllers may not contain financial or state-transition logic.
- React may not decide permissions or authoritative eligibility.
- Models may expose relationships and small invariants; avoid giant active-record models.
- External API payloads must be mapped into internal data objects.
- Domain code must not depend directly on an HTTP response format.
- Bybit-specific terms stay in the Bybit adapter except provider IDs/status mappings that the Payments domain explicitly records.
- Notifications must not determine whether a business transaction succeeds.
- Admin tools must use the same domain actions and ledger as user flows.
- No direct database writes outside migrations, seeders for safe reference data, factories, repositories/actions, or approved maintenance commands.

## 10. Money and Ledger Rules

These rules are non-negotiable:

- Never use floating-point arithmetic.
- Store monetary values as integer atomic units with currency and scale.
- Parse user amount input from validated decimal strings.
- Reject extra decimal places instead of rounding silently.
- Every financial event has a unique business reference.
- Every committed ledger transaction balances to zero for its currency.
- Ledger entries are append-only.
- Never edit or delete a committed ledger entry.
- Corrections use compensating entries.
- Never update `users.balance`, `wallet.balance`, or any cached balance directly.
- If cached balances exist, update them in the same transaction as ledger entries.
- Use row locks for spending, reservation, release, withdrawal hold, and administrative adjustment.
- Check sufficient balance inside the locked database transaction.
- Approval of a submission and financial settlement must be atomic.
- Failed notifications do not reverse a committed financial event.
- Financial actions must be idempotent.
- Add concurrency tests for operations that can spend or credit money.

## 11. Bybit Pay Rules

- Read the current official Bybit Pay documentation before implementing or changing integration code.
- Use the official sandbox before production.
- Keep API keys, secrets, private keys, and merchant IDs on the server.
- Never expose secrets through Inertia props, JavaScript bundles, logs, exceptions, emails, or database audit descriptions.
- Never trust a client-side redirect or screenshot as payment confirmation.
- Create the internal intent before calling Bybit.
- Generate each merchant trade number once.
- Store provider IDs with unique constraints.
- Verify webhook signature against the exact raw body.
- Verify timestamp freshness, merchant identity, amount, currency, internal reference, and allowed transition.
- Deduplicate provider events before changing state.
- Expect delayed, repeated, and out-of-order webhooks.
- A network timeout after payout submission is an unknown result, not a failure and not permission to retry immediately.
- Query/reconcile unknown or pending results.
- Do not manually mark a payout paid without an approved reconciliation procedure and audit evidence.
- Do not log full provider payloads by default.
- Test signature verification using official fixtures and negative cases.
- Payment code cannot be considered complete without idempotency and reconciliation tests.

## 12. Authentication and Authorization

- Use Laravel session authentication for the web app.
- Regenerate sessions after login.
- Invalidate sessions correctly after password/security changes.
- Use verified email middleware where required.
- Confirm password and require configured 2FA for withdrawals and other sensitive actions.
- Every resource action needs an ownership or permission policy.
- Hiding a button is not authorization.
- Staff roles follow least privilege.
- Staff permission changes and sensitive actions are audited.
- Support agents cannot see secrets or unrelated financial/KYC data.
- Error responses must not reveal whether another user's resource exists.

## 13. Validation

- Validate on the server even when the client validates.
- Use allowlists for enums, file types, sort fields, filters, and state transitions.
- Normalize email, username, URL, and money input deliberately.
- Validate URLs against allowed protocols.
- Reject private/internal network URLs if a server later fetches user-supplied URLs.
- Apply maximum lengths to every text field.
- Sanitize output through framework escaping; do not attempt ad hoc input HTML cleaning as the primary defense.
- Rich text is not allowed until a reviewed sanitizer and rendering policy exist.
- Campaign instructions begin as plain text with safe line breaks.

## 14. File Uploads

- Store uploads privately.
- Authorize every download.
- Use random storage names.
- Validate extension, detected MIME type, file size, and image dimensions.
- Reject executables, archives unless explicitly needed, HTML, and SVG.
- Never execute, include, or render uploaded content as application code.
- Use safe content-disposition when serving files.
- Do not trust the client-provided MIME type.
- Do not place proof files directly under `public/`.

## 15. Database and Migration Rules

- Migrations must be deterministic and reversible when safely possible.
- Never modify an old production migration to change deployed schema; add a new migration.
- Add foreign keys, unique constraints, and indexes intentionally.
- Do not use cascading deletion for financial, audit, or provider-event records unless retention requirements explicitly allow it.
- Use restrictive deletes for ledger relationships.
- Seeders contain only safe reference/dev data.
- Factories create test data, not production balances.
- A destructive migration needs an explicit backup/rollback plan and user approval.
- Never run destructive database commands against production.
- Never drop or truncate production data to “fix” a migration.

## 16. Dependency Rules

Use the stack and approved libraries in `Architecture.md`.

Before adding a package:

1. Explain what requirement it satisfies.
2. Check whether Laravel or the existing stack already provides the capability.
3. Check maintenance, security, compatibility, and license.
4. Add only one library for one concern.
5. Lock the version.
6. Add tests.
7. Record it in `memory.md`.

Avoid:

- Multiple UI libraries.
- Duplicate form libraries.
- Duplicate authorization systems.
- Unofficial payment SDKs without a clear benefit.
- Packages that obscure ledger behavior.
- Abandoned packages.
- Runtime CDN dependencies for core application code.

## 17. Error-Handling Standard

### Expected business errors

Use typed exceptions or result objects and map them to safe, actionable messages:

- Insufficient balance.
- Ineligible country.
- Account verification required.
- Invalid state transition.
- Campaign no longer available.
- Duplicate request.
- Provider temporarily unavailable.
- Withdrawal under review.

### Unexpected errors

- Generate or preserve a correlation ID.
- Log safe context.
- Return a generic message.
- Preserve the user's data and current business state.
- Do not reveal stack traces, SQL, filesystem paths, environment values, signatures, or provider bodies.

### API/provider errors

- Separate `DECLINED`, `FAILED`, `TIMEOUT`, `UNAVAILABLE`, and `UNKNOWN`.
- Retry only when safe and explicitly configured.
- Use exponential backoff with bounded attempts.
- Send unknown payment outcomes to reconciliation.
- Do not convert all errors to HTTP 200.

### UI errors

- Put field errors beside fields.
- Keep entered non-sensitive form values.
- Explain the recovery action.
- Provide a retry only when it is safe.
- Never show “success” before the server confirms the operation.

## 18. Logging, Audit, and Privacy

- Diagnostic logs and audit records are different.
- Audit sensitive staff/user actions with actor, action, entity, before/after safe fields, reason, time, and correlation ID.
- Financial audit events are append-only.
- Redact tokens, passwords, signatures, keys, raw webhook payloads, session IDs, and unnecessary personal data.
- Do not log proof contents or ticket attachments.
- Use UTC for storage.
- Respect retention and deletion policy.

## 19. Testing Rules

For every feature, test:

- Happy path.
- Validation failure.
- Unauthorized actor.
- Wrong owner.
- Invalid state.
- Duplicate request where applicable.
- Concurrent request where money or quantity is involved.
- Provider failure and unknown result where applicable.

Minimum payment tests:

- Valid signed webhook.
- Invalid signature.
- Stale timestamp.
- Duplicate event.
- Mismatched amount/currency/merchant/reference.
- Out-of-order state.
- Provider query mismatch.
- Payout network timeout after submission.
- Ledger idempotency.

Never weaken a failing test merely to make the suite pass. Fix the cause or document a legitimate requirement change.

## 20. User Interface Rules

- Follow `design.md`.
- Use Inter.
- Use the defined indigo and emerald palette.
- No gradients, neon effects, glassmorphism, excessive motion, or crypto-casino styling.
- Use icons from `lucide-react`.
- Do not use emojis as interface icons.
- Support mobile widths from 320px upward.
- Include loading, empty, error, disabled, and success states.
- Do not communicate status with color alone.
- Respect reduced-motion preferences.
- Keep financial status and fee explanations explicit.
- Public pages and authenticated pages use different layouts.
- The public site does not show an authenticated notification bell.

## 21. Git and Change Safety

- Inspect `git status` before editing.
- Preserve unrelated user changes.
- Make focused commits when the user requests commits.
- Do not commit `.env`, credentials, uploads, logs, build output, or dependencies.
- Never use destructive Git commands without explicit permission.
- Do not overwrite a user-edited file blindly.
- Explain material schema or architecture changes.

## 22. AI Boundaries

Claude may:

- Inspect code and documentation.
- Propose a bounded plan.
- Write application code, migrations, tests, and documentation.
- Use sandbox/fake provider implementations in test environments.
- Run local non-destructive checks.
- Update `memory.md`.

Claude must stop and ask before:

- Enabling production Bybit Pay.
- Using or rotating real payment credentials.
- Sending a real payout or creating a real deposit.
- Changing country eligibility or legal gating.
- Changing fee, minimum withdrawal, or financial policy not already specified.
- Running a destructive migration or deleting user data.
- Reversing or adjusting real balances.
- Disabling security checks, audit, idempotency, or reconciliation.
- Introducing a major new framework or replacing the selected stack.
- Deploying to production when the user requested only code.

Claude must never:

- Invent merchant approval.
- Claim legal approval exists without evidence.
- Help evade provider eligibility, age limits, KYC, sanctions, or country restrictions.
- Place secrets in source code.
- fabricate completed work, passing tests, provider results, or production metrics.
- Execute real money movement.
- Credit a balance by direct SQL or model increment.
- hide a payment error to make a demo appear successful.

## 23. Definition of Done

A feature is done only when:

- It matches the PRD and current phase.
- Its data model and state transitions are implemented.
- Server validation and authorization exist.
- Financial effects use the ledger.
- Normal and failure UI states exist.
- Relevant automated tests pass.
- Formatting/static checks pass.
- No secrets or sensitive logging were introduced.
- Documentation and `memory.md` are current.
- Remaining limitations are stated honestly.

If any item is missing, report the feature as in progress.
