# Ellipzo Project Memory

**Memory version:** 1.0  
**Last updated:** 2026-07-23  
**Current phase:** Phase 1 — Foundation, Design System, and Identity  
**Current status:** Task 1 (Laravel 13 + React/TS/Inertia/Tailwind foundation) **complete**. Laravel 13.21.1 + PHP 8.4.14 + Inertia 3 (v3.1.1) + React 19.2.8 + TypeScript 6.0.3 + Tailwind CSS 4.3.3 + Vite 8.1.5 verified end to end via `composer install`, `npm install`, a production build, type-check, lint, format-check, Pint, and Pest, all run from a clean checkout of the committed lockfiles. **The live app is not yet reachable in a browser** — `SESSION_DRIVER=database` (per Architecture.md) needs MySQL, which is not installed. See §8 risk row "Live app returns HTTP 500 until MySQL exists."  
**Current working file:** None (Task 1 closed)  
**Next recommended file:** Install/verify MySQL 8, then `database/migrations/` for Phase 1's identity schema (users, profiles, consents, country capabilities, staff roles/permissions) per `docs/phases.md` Phase 1 deliverables

## 1. Purpose of This File

This is the short operational memory for Claude. It records what is actually complete, what is currently being worked on, important decisions, blockers, tests, and the exact next task.

This file does not replace product or architecture requirements. If it conflicts with the other documents, follow the priority in `rules.md` and record the conflict.

Claude must update this file at the end of every working session.

## 2. Project Snapshot

### Product

Ellipzo is a production-grade micro-earning and advertising platform at `ellipzo.com`.

Eligible users can:

- Complete tasks, surveys, and offerwalls to earn rewards.
- Deposit advertising funds.
- Create and manage campaigns.
- Review campaign submissions.
- Dispute submission decisions.
- Refer users.
- Open support tickets.

One normal account can both earn and advertise. There are no separate earner and advertiser roles.

### Payment priority

- Advertising deposits: Bybit Pay one-time QR/checkout.
- Earning withdrawals: Bybit Pay merchant payout.
- Bybit Pay integration begins in sandbox only.
- Live payment activation requires merchant approval, eligible adult/legal-entity operation, country approval, and operational readiness.

### Product positioning

- Not an investment platform.
- Not a trading, staking, lending, betting, gambling, or guaranteed-income product.
- Rewards require completed and approved work.

## 3. Locked Decisions

These decisions are approved unless the user explicitly changes them:

| Area | Decision |
| --- | --- |
| Product | Full production-oriented platform, not a demo-only build |
| Account model | One normal account can earn and advertise |
| Staff model | Permission-based admin/moderator/finance/support access |
| Backend | PHP with Laravel modular monolith |
| Framework baseline | Laravel 13, PHP 8.3+ |
| Frontend | React 19 + TypeScript + Inertia 3 |
| Styling | Tailwind CSS 4 with customized shadcn/ui primitives |
| Database | MySQL 8 |
| Hosting direction | Hostinger-compatible; VPS preferred before live payment scale |
| Primary payment provider | Bybit Pay |
| Deposit purpose | Advertising balance |
| Withdrawal source | Available earning balance |
| Financial source of truth | Immutable balanced ledger |
| Primary color | Indigo `#4F46E5` |
| Success color | Emerald `#10B981` |
| Font | Inter |
| Visual exclusions | No gradients, neon, glassmorphism, or crypto-casino styling |
| Earner UX | Mobile-first |
| Advertiser/admin UX | Dashboard-first and responsive |
| Public notifications | No notification bell on public pages |
| Unread indicator | Red dot only when unread notifications exist |
| Support CTA | Prominent green “Create New Ticket” button |

## 4. Compliance and Safety State

### Nepal

Current official Nepal Rastra Bank publications state that cryptocurrency transactions and cryptocurrency-related business are illegal in Nepal.

Therefore:

- Nepal live crypto deposits and withdrawals are disabled.
- Nepal can be used for non-transactional development and sandbox testing only.
- No VPN, false-country, KYC, age, or provider restriction bypass will be built or documented.
- Nepal can become a live market only after a documented change and written legal clearance.

### User/provider eligibility

- Payment services are restricted to eligible adults in approved countries.
- The Bybit merchant account must be held and operated by an eligible adult or legal entity.
- Claude cannot enable production payments or use real credentials.

### Current Bybit Pay documentation check

Checked on 2026-07-23 against official Bybit Pay documentation:

- One-time QR payment flow exists.
- Payment creation returns checkout/QR data.
- Payment result can be queried.
- Payment result is sent by webhook.
- Webhook verification uses signed raw request content and timestamp.
- Merchant payout capability is documented.
- Sandbox and reconciliation must be used before production.

The implementation phase must re-read the current official documentation because provider fields, versions, signing, status values, and eligibility can change.

## 5. Completed Work

### Planning documents created on 2026-07-23

- [x] `docs/prd.md`
  - Product, users, goals, features, business rules, payments, compliance, and acceptance criteria.
- [x] `docs/Architecture.md`
  - App flow, modular boundaries, folder/file structure, stack, database, ledger, Bybit Pay, testing, and deployment.
- [x] `docs/rules.md`
  - Mandatory coding, money, payment, error-handling, dependency, safety, and AI boundaries.
- [x] `docs/phases.md`
  - Ten ordered development phases with copy-ready Claude prompts.
- [x] `docs/design.md`
  - Colors, typography, components, layouts, responsive behavior, content, and accessibility.
- [x] `docs/memory.md`
  - Current project state and handoff protocol.

### Code

- [x] Laravel repository initialized 2026-07-23 (`laravel/laravel` v13.8.0 skeleton → `laravel/framework` v13.21.1, PHP 8.4.14).
- [x] Inertia 3 (`inertiajs/inertia-laravel` v3.1.1), React 19.2.8, TypeScript 6.0.3, Tailwind CSS 4.3.3, Vite 8.1.5 installed and wired (see §12 session log for the full dependency list and reasons).
- [x] Pest 4.7.5 + `pestphp/pest-plugin-laravel` v4.1.0 installed; example tests converted from PHPUnit class style to Pest functional style; both pass.
- [x] Laravel Pint (default preset, no custom `pint.json`) passes.
- [x] ESLint (flat config) + Prettier configured; both pass with zero warnings.
- [ ] No product-domain migration has been created yet (only the framework's default `users`/`cache`/`jobs` migrations exist).
- [ ] No Bybit credential has been used.
- [ ] No live payment has been enabled.
- [ ] MySQL 8 is not installed — see §8 risk row.

Do not infer that older Appwrite, Node/Knex, or PHP experiments are part of the new Laravel baseline. None were found in this directory; the project folder contained only `docs/` and `CLAUDE.md` before Task 1 began.

## 6. Current Phase Checklist

### Phase 0 — Product and Compliance Baseline

- [x] Create the six planning documents.
- [x] Validate filenames, Markdown code fences, source-of-truth order, and cross-document stack/product constraints.
- [x] Record Bybit Pay as the primary planned provider.
- [x] Record Nepal live-crypto restriction.
- [x] Define production payment activation gates.
- [ ] User reviews and approves the documents.
- [ ] Confirm eligible adult/legal entity responsible for merchant operation.
- [ ] Confirm first legally approved live launch country or countries.
- [ ] Confirm Bybit Pay sandbox merchant access status.
- [ ] Confirm initial supported currency.
- [ ] Confirm platform fee model.
- [ ] Confirm deposit limits and fees.
- [ ] Confirm withdrawal limits and fees.
- [ ] Confirm task review and dispute windows.
- [ ] Confirm withdrawal manual-review policy.
- [ ] Confirm campaign cancellation/refund rules.
- [ ] Confirm actual Hostinger plan and server capabilities.
- [ ] Inspect or initialize the application repository.
- [ ] User approves moving to Phase 1.

Phase 0 remains **in progress** until the blocking decisions are resolved. Task-level work on Phase 1 proceeded in parallel per explicit user instruction; this does not mark Phase 0 complete.

### Phase 1 — Task 1: Laravel 13 + React/TS/Inertia/Tailwind foundation

- [x] Laravel 13 application scaffolded (bare `laravel/laravel`, not `laravel/react-starter-kit` — see D-013).
- [x] Inertia 3 backend adapter installed and middleware registered in `bootstrap/app.php`.
- [x] React 19 + TypeScript (strict mode) + Vite wired; Tailwind CSS 4 (already present in the Laravel 13 skeleton) confirmed working through the same Vite pipeline.
- [x] Minimal end-to-end proof page (`resources/js/pages/public/welcome.tsx`) rendered through `routes/web.php` → `Inertia::render()` → `resources/views/app.blade.php`.
- [x] Pint, ESLint, Prettier, Pest configured and passing.
- [x] `.env.example` updated to `APP_NAME=Ellipzo`, `DB_CONNECTION=mysql` (Architecture.md mandates MySQL 8; no sqlite fallback left in place).
- [x] `git init` run; `.gitignore` verified to exclude `.env`, `vendor/`, `node_modules/`, `public/build/`, `storage/logs/*.log`. No commit made (not requested).
- [ ] Not in scope for Task 1 (deferred to later Phase 1 tasks): design tokens, public/authenticated/auth/admin layouts, registration/login, Google Socialite, email verification, profile, country capabilities, staff roles/permissions, admin shell, audit base.
- [ ] MySQL 8 installation — explicitly deferred by user choice ("scaffold now, install MySQL before DB steps"); the live app cannot serve a request until this is done (§8).

## 7. Unresolved Decisions

Claude must not guess these:

| ID | Decision needed | Why it matters | Current safe default |
| --- | --- | --- | --- |
| D-001 | Which country launches first? | Legal and provider availability | No live country enabled |
| D-002 | Who is the eligible merchant operator/entity? | Bybit merchant ownership and contracts | Production payments disabled |
| D-003 | Is Bybit Pay sandbox merchant access approved? | Phase 5 dependency | Use fake adapter tests only |
| D-004 | Is USDT the only initial currency? | Money scale, UI, ledger, provider requests | Design supports currencies; no live currency enabled |
| D-005 | Platform fee structure | Campaign reservation and ledger postings | Fee not charged until approved |
| D-006 | Minimum/maximum advertising deposit | Validation and risk | No live deposits |
| D-007 | Minimum/maximum withdrawal and withdrawal fee | Holds, UI, risk, ledger | No live withdrawals |
| D-008 | Task review deadline | Submission state machine | Must be configured before launch |
| D-009 | Dispute deadline and escalation timing | Dispute state machine | Must be configured before Phase 7 |
| D-010 | Manual versus automatic payout approval thresholds | Risk and operations | All sandbox payouts require review |
| D-011 | Campaign cancellation and unused-budget release/refund policy | Campaign states and ledger | Release internal reservation only; no provider refund assumption |
| D-012 | Hostinger shared plan or VPS | Queue, Redis, deployment, monitoring | Build shared-compatible; prefer VPS before live payments |
| D-013 | **RESOLVED 2026-07-23.** How to obtain a Laravel 13 + React + Inertia + Tailwind foundation, since `laravel/react-starter-kit` v1.0.1 (latest stable tag) requires `laravel/framework: ^12.0` | Architecture.md mandates Laravel 13; `composer create-project laravel/react-starter-kit` resolved `laravel/framework v12.64.0`, not 13.x. Packagist confirmed Laravel 13 itself is released (up to v13.21.1). The starter kit's `dev-main` branch targets Laravel 13 but pulls unapproved packages (`laravel/fortify`, `laravel/wayfinder`, `laravel/chisel`) and is an unpinned dev branch. | **User decision: scaffold the bare `laravel/laravel` skeleton (stable on `^13.8`, resolved v13.8.0 → framework v13.21.1) and manually wire Inertia 3, React 19, TypeScript, and Tailwind 4.** Implemented and verified in this session — see §12 session log and the Phase 1 Task 1 checklist above. |

## 8. Known Risks

| Risk | Level | Mitigation |
| --- | --- | --- |
| Bybit merchant eligibility/approval unknown | Blocker | Sandbox/fake only until approval evidence exists |
| Financial duplication from callbacks/retries | Critical | Unique references, raw-body signature verification, ledger idempotency, reconciliation |
| Campaign overspend under concurrency | Critical | Database transaction, row locks, reservation ledger, concurrency tests |
| Withdrawal duplicate after timeout | Critical | Unknown state, no blind retry, query/reconcile |
| Shared hosting queue reliability | High | Durable webhook persistence; move to supervised VPS worker before live scale |
| Old experimental code conflicting with baseline | Medium | Inspect repository; do not copy blindly |
| Scope too large for one AI prompt | High | Follow phases and small-task prompts |
| **Live app returns HTTP 500 until MySQL exists** | High (local dev) | `.env` correctly sets `SESSION_DRIVER=database`/`CACHE_STORE=database`/`QUEUE_CONNECTION=database` per Architecture.md. Confirmed 2026-07-23 via `php artisan serve` + live request: every request 500s with `SQLSTATE[HY000] [2002] No connection could be made` (no `sessions` table, no MySQL server). The automated Pest suite still passes because `phpunit.xml` overrides to array-driver sessions for the `testing` environment, so it never touches a real database. Deliberately left as the architecturally-correct config rather than papering over the gap with `SESSION_DRIVER=file`. Install/configure MySQL 8 and run `php artisan migrate` before attempting to browse the app or continue Phase 1. |
| `laravel/framework` requires PHP `^8.3`, but `pestphp/pest` v4 (latest stable, installed) requires PHP `^8.4` | Low | Dev-only constraint (Pest is `require-dev`, never ships to production), and the installed local PHP is already 8.4.14, so it does not block this machine. Flagging because Architecture.md states "PHP 8.3+" as the floor — a contributor/CI runner on PHP 8.3 exactly could install the app but not run the Pest suite. No action taken; noted for awareness. |
| `gd` and `intl` PHP extensions disabled locally | Low | Not required for Task 1. `gd` is needed for Phase 3 (proof-image dimension validation). Enable before starting that phase. |

## 8a. Task 1 Dependency Decisions

Per `rules.md` §16, every new dependency needs a recorded reason. All versions below are exact-resolved values from `composer.lock`/`package-lock.json` as of 2026-07-23.

| Package | Version | Reason |
| --- | --- | --- |
| `laravel/laravel` (skeleton, not a runtime dep) | v13.8.0 → `laravel/framework` v13.21.1 | Architecture.md mandates Laravel 13; `laravel/react-starter-kit` was rejected — see D-013. |
| `inertiajs/inertia-laravel` | v3.1.1 | Architecture.md mandates "Inertia 3"; officially supports `laravel/framework: ^11.0\|^12.0\|^13.0`. |
| `react`, `react-dom` | 19.2.8 | Architecture.md mandates React 19. |
| `@inertiajs/react` | 3.6.1 | JS-side counterpart to `inertiajs/inertia-laravel` 3.x. |
| `typescript` | **6.0.3, pinned exact** (not the npm `latest` tag) | The `latest` dist-tag (7.0.2) is incompatible with `@typescript-eslint` (`typescript-eslint@8.65.0` requires `typescript: >=4.8.4 <6.1.0`). Using TS 7 would silently break lint type-checking. Pinned exact (no `^`) so a routine `npm install` cannot drift past the compatibility ceiling. |
| `tailwindcss`, `@tailwindcss/vite` | 4.3.3 | Already present in the Laravel 13 skeleton's default `package.json`; matches Architecture.md's "Tailwind CSS 4." |
| `vite` | 8.1.5 | Shipped by the Laravel 13 skeleton; `laravel-vite-plugin` 3.1.3 and `@vitejs/plugin-react` 6.0.4 both declare `vite: ^8.0.0` as a peer, so this is the only compatible major. |
| `laravel-vite-plugin` | 3.1.3 | Shipped by the Laravel 13 skeleton. |
| `@vitejs/plugin-react` | 6.0.4 | Required to compile React 19 JSX/Fast-Refresh through Vite; not in the bare skeleton by default because the skeleton ships framework-agnostic. |
| `@types/react`, `@types/react-dom` | 19.2.17 / 19.2.3 | Type definitions matching the installed React 19 line. |
| `eslint` | 10.7.0 | Architecture.md mandates ESLint. |
| `typescript-eslint` | 8.65.0 | Meta-package bundling the TS parser + recommended rules; latest stable, compatible with ESLint 10 and pinned TS 6.0.3. |
| `eslint-plugin-react-hooks` | 7.1.1 | Standard React Hooks lint rules. |
| `eslint-plugin-react-refresh` | 0.5.3 | Warns on exports that break Vite Fast Refresh. |
| `eslint-config-prettier` | 10.1.8 | Disables ESLint formatting rules that conflict with Prettier. |
| `prettier` | 3.9.6 | Architecture.md mandates Prettier. |
| `globals`, `@eslint/js` | 17.7.0 / 10.0.1 | Required building blocks for the ESLint 10 flat-config format. |
| `pestphp/pest` | 4.7.5 | Architecture.md mandates Pest. Latest stable requires PHP `^8.4` — see §8 risk row. |
| `pestphp/pest-plugin-laravel` | 4.1.0 | Laravel-specific Pest assertions/helpers (`RefreshDatabase`, HTTP test helpers), matching Pest 4's major version. |

No package outside this list, and outside what the Laravel 13 skeleton ships by default (`laravel/tinker`, `laravel/pail`, `laravel/pint`, `laravel/pao`, `nunomaduro/collision`, `mockery/mockery`, `fakerphp/faker`, `laravel/agent-detector`, `phpunit/phpunit` as Pest's engine, `concurrently`), was added. Ziggy (`tightenco/ziggy`) was deliberately **not** added — it appeared as a dependency of the rejected `react-starter-kit` but is not in Architecture.md's approved list and nothing in Task 1 needs named JS routes yet.

## 9. Environment Variable Names

Names only; never store real values here:

```text
APP_NAME
APP_ENV
APP_KEY
APP_DEBUG
APP_URL

DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD

SESSION_DRIVER
CACHE_STORE
QUEUE_CONNECTION

MAIL_MAILER
MAIL_HOST
MAIL_PORT
MAIL_USERNAME
MAIL_PASSWORD
MAIL_ENCRYPTION
MAIL_FROM_ADDRESS
MAIL_FROM_NAME

GOOGLE_CLIENT_ID
GOOGLE_CLIENT_SECRET
GOOGLE_REDIRECT_URI

BYBIT_PAY_ENABLED
BYBIT_PAY_ENV
BYBIT_PAY_BASE_URL
BYBIT_PAY_VERSION
BYBIT_PAY_API_KEY
BYBIT_PAY_PRIVATE_KEY_OR_SECRET
BYBIT_PAY_MERCHANT_ID
BYBIT_PAY_WEBHOOK_PUBLIC_KEY
BYBIT_PAY_WEBHOOK_URL

FILESYSTEM_DISK
LOG_CHANNEL
```

Production must fail closed if payment configuration is missing, mixed with sandbox, or invalid.

## 10. Phase 1 Planned Acceptance Tests

- Registration creates a normal user with no earner/advertiser role.
- Invalid age/country/payment capability denies protected payment actions server-side.
- Email verification gates protected actions.
- Password reset and session handling work.
- Google login safely creates or links an account.
- A user can view and edit only their profile.
- Legal consent version and timestamp are stored.
- A normal user cannot access `/admin`.
- Staff permissions allow only assigned actions.
- Nepal has deposits and withdrawals disabled in country capabilities.
- No secret appears in page props or logs.
- Public pages have no notification bell.
- Core layouts work at 320px and desktop widths.

## 11. Immediate Next Action

Phase 1 Task 1 (foundation scaffold) is complete. Two independent threads remain open:

**A. Phase 0 baseline decisions** (still not blocking further Phase 1 foundation work, but blocking before real financial/legal-sensitive phases):

1. Ask the user for the unresolved decisions D-001 through D-012 in small groups.
2. Update this file with confirmed answers.
3. Mark Phase 0 complete only after approval.

**B. Phase 1 continuation** (recommended immediate next task):

1. Install and configure MySQL 8 locally; update `.env` credentials; run `php artisan migrate` — this unblocks the live app, which currently 500s on every request (§8).
2. Only after that: begin the next bounded Phase 1 task — design tokens from `docs/design.md` plus the public/authenticated/auth/admin layout shells — per the Phase 1 deliverables list in `docs/phases.md`. Do not add authentication logic, wallet balances, or Bybit Pay in this next task; those remain out of scope per `docs/phases.md` Phase 1/2/5/6 boundaries.
3. Use the Small-Task Prompt Template in `docs/phases.md` §14 to scope that next task narrowly, as this session did for Task 1.

## 12. Session Log

| Date | Phase | Summary | Files | Tests | Result |
| --- | --- | --- | --- | --- | --- |
| 2026-07-23 | 0 | Created and consistency-checked the complete product, architecture, rules, phases/prompts, design, and memory baseline. Recorded Bybit Pay priority and Nepal live-payment restriction. | Six documentation files | File/fence/constraint checks passed; no application tests applicable | Phase 0 in progress |
| 2026-07-23 | 1 (Task 1) | Inspected environment (PHP 8.4.14, Composer 2.9.5, Node 24.11.0, npm 11.6.1, Git 2.52.0, no MySQL server, ext-zip/gd/intl disabled, ext-bcmath/pdo_mysql enabled). Backed up `docs/` + `CLAUDE.md` to scratchpad with checksums. Ran `composer create-project laravel/react-starter-kit` into a scratchpad temp dir to verify the resolved Laravel version before touching the repo root. Discovered it resolves `laravel/framework v12.64.0` (Laravel 12), not Laravel 13. Confirmed via `composer show -a` that Laravel 13 exists on Packagist (up to v13.21.1) but the starter kit's latest stable tag v1.0.1 pins `^12.0`; only its `dev-main` branch targets `^13.17`. **Stopped before any write to the project root** per explicit user instruction to stop and report if Laravel 13 does not resolve. | None in project root; scratchpad-only: `docs-backup-phase1-task1/`, `ellipzo-scaffold/` (Laravel 12 probe, discarded), `probe/composer.json.main` | No application tests run (blocked before scaffold merge) | **Blocked — awaiting user decision on D-013** |
| 2026-07-23 | 1 (Task 1) | **D-013 resolved by user**: scaffold bare `laravel/laravel` (stable on Laravel 13) and manually wire Inertia 3 + React 19 + TypeScript + Tailwind 4, instead of the Laravel-12-only `react-starter-kit` or its unpinned `dev-main` branch. Researched and cross-checked exact compatible versions before installing anything (caught that npm's `typescript@latest` is 7.0.2, incompatible with `typescript-eslint`'s `<6.1.0` ceiling; pinned TS to 6.0.3 instead). Scaffolded `laravel/laravel` v13.8.0 → `laravel/framework` v13.21.1 in a scratchpad temp dir; confirmed via `php artisan --version`. Installed and wired: `inertiajs/inertia-laravel` v3.1.1 (ran `artisan inertia:middleware`, registered in `bootstrap/app.php`); React 19.2.8 + `@inertiajs/react` 3.6.1; TypeScript 6.0.3 + `tsconfig.json` (strict mode); Vite 8.1.5 + `@vitejs/plugin-react` 6.0.4 (Tailwind 4 was already wired by the Laravel 13 skeleton); ESLint 10 flat config + Prettier; Pest 4.7.5 + `pestphp/pest-plugin-laravel` 4.1.0 (no `pest:install` artisan command existed, so `tests/Pest.php` was hand-written and the two example tests were converted from PHPUnit class style to Pest functional style). Built a minimal end-to-end proof page (`routes/web.php` → `Inertia::render('public/welcome')` → `resources/js/pages/public/welcome.tsx`) and removed the now-unused default `welcome.blade.php`. Deliberately did **not** add the `@routes` (Ziggy) Blade directive — Ziggy is not in Architecture.md's approved dependency list. Updated `.env.example` to `APP_NAME=Ellipzo` and `DB_CONNECTION=mysql` (was `sqlite`); removed the stray `database/database.sqlite` the skeleton auto-creates. Ran the full verification suite twice — once in the scratchpad scaffold, once again after merging into the real project root and running fresh `composer install`/`npm install` from the committed lockfiles, to prove the lockfiles are authoritative and reproducible. Also started `php artisan serve` and made a live HTTP request: got a 500 (`SQLSTATE[HY000] [2002] No connection could be made` — no MySQL, `SESSION_DRIVER=database` needs the `sessions` table). Confirmed this is why the Pest suite still passes (its `phpunit.xml` testing environment uses array-driver sessions, never touching a real DB) and left `.env` architecturally correct rather than swapping to `file`/`array` drivers to mask the gap. Ran `git init`; verified `.gitignore` correctly excludes `.env`, `vendor/`, `node_modules/`, `public/build/`, `storage/logs/*.log` via `git check-ignore`. No commit made (not requested). Re-verified `docs/` and `CLAUDE.md` byte-identical to the pre-task backup (sha256) throughout, both after the merge and at session end. | See the full file list in the Task 1 checklist above (§6); summary: `composer.json`/`composer.lock`, `package.json`/`package-lock.json`, `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, `resources/views/app.blade.php` (new, replaces deleted `welcome.blade.php`), `resources/js/app.tsx` (new), `resources/js/pages/public/welcome.tsx` (new), `vite.config.ts` (replaces `vite.config.js`), `tsconfig.json` (new), `eslint.config.js` (new), `.prettierrc.json`/`.prettierignore` (new), `tests/Pest.php` (new), `tests/Feature/ExampleTest.php` + `tests/Unit/ExampleTest.php` (rewritten to Pest style), `.env`/`.env.example` (MySQL config, `APP_NAME=Ellipzo`) | `php artisan --version` — Laravel 13.21.1, pass. `npm run build` — pass (Vite 8, 550 modules, ~833ms). `npm run types` (`tsc --noEmit`) — pass after removing deprecated `baseUrl` from `tsconfig.json`. `npm run lint` (ESLint, `--max-warnings 0`) — pass. `npm run format:check` (Prettier) — pass. `./vendor/bin/pint --test` — pass. `./vendor/bin/pest` — 2/2 tests, 2/2 assertions, pass. Live smoke test (`php artisan serve` + `curl /`) — **HTTP 500**, expected given no MySQL server (see §8 risk row); not a defect in the scaffold itself. All checks re-run and re-confirmed passing in the real project root after merge, not only in the scratchpad. | Chose bare `laravel/laravel` + manual wiring over the `react-starter-kit` (D-013, user decision). Pinned TypeScript to exact 6.0.3 to avoid the `typescript-eslint` compatibility ceiling. Did not add Ziggy. Kept `SESSION_DRIVER=database` etc. as architecturally correct despite causing a live 500 without MySQL, rather than temporarily weakening the config. Full dependency list and reasons recorded in §8a. | **Complete for Task 1's scope.** Remaining, not blocking: MySQL 8 not installed (live app 500s — §8); Phase 0 business decisions D-001–D-012 still open; `gd`/`intl` PHP extensions still disabled (needed later, not now). See §11 for the exact next task. | **Task 1 complete** |

## 13. Required Update Template

Claude must replace the current-state fields at the top and append a session-log row.

```text
Date:
Phase:
Task:
Status: not started | in progress | blocked | complete
Current working file:

Completed:
- ...

Files changed:
- ...

Migrations:
- ...

Tests/checks:
- command — pass/fail

Decisions:
- ...

Blockers/risks:
- ...

Next exact task:
- ...

Next recommended file:
- ...
```

Do not delete earlier decisions or session history. If a decision changes, mark the old decision superseded and record who changed it and why.
