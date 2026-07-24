# Ellipzo Project Memory

**Memory version:** 1.0  
**Last updated:** 2026-07-24  
**Current phase:** Phase 1 — Foundation, Design System, and Identity  
**Current status:** Task 1 (foundation), Task 2 (database readiness), and Task 3 (design tokens + layout shells) are **complete**. A small Windows dev-tooling maintenance fix is also **complete**: `composer run dev:windows` now exists as a Pail-free alternative to `composer run dev`, verified to stay running (unlike `dev`, which Pail's missing `pcntl` dependency crashes on native Windows). The live app works end to end on `DB_DATABASE=ellipzo_app`.  
**Current working file:** None (maintenance fix closed)  
**Next recommended file:** No auth system exists yet — the next bounded Phase 1 task is registration/login/email verification (`docs/phases.md` Phase 1 deliverables), which is what will let `app-layout.tsx`/`admin-layout.tsx` finally be mounted on real routes.

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
- [x] MySQL 8 installation gap resolved differently than expected — see Task 2 below.

### Phase 1 — Task 2: Database readiness (COMPLETE)

- [x] Checked whether MySQL 8 is installed and running. Finding: MySQL 8 itself is **not** installed; XAMPP's bundled **MariaDB 10.4.32** is installed and was already running on port 3306 — no install/admin/GUI action was needed. User explicitly chose (2026-07-23) to use this MariaDB instance for local dev and record it as a documented deviation from Architecture.md's "MySQL 8," rather than installing real MySQL 8 alongside it.
- [x] No admin/GUI installation step was required (MariaDB was already running), so the "stop and give manual steps" branch did not trigger for that reason. A different, more serious stop condition was hit instead — resolved, see D-014.
- [x] **Resolved via D-014, then completed:** discovered a pre-existing `ellipzo` database with an unrelated ~44-table schema (apparently real prior work, not disposable — see D-014). User chose to use a different database name rather than touch it. Created a fresh, genuinely empty **`ellipzo_app`** database instead. The original mystery `ellipzo` database and its `data_old` backup were left **completely untouched** — not read again, not dropped, not repaired.
- [x] Configured `.env` with a dedicated scoped MySQL user. The user first created for the mystery database was **fully removed** (`DROP USER`) rather than merely repointed, and a fresh user `ellipzo_app`@`127.0.0.1` was created with `GRANT ALL ON ellipzo_app.*` only — verified it has **zero access** to the old `ellipzo` database (`ERROR 1044 Access denied` confirmed). Password generated and written into `.env` via `sed`; never appeared in any command output or this file.
- [x] Confirmed sessions/cache/queue migrations already exist in this Laravel scaffold (`sessions` inside `0001_01_01_000000_create_users_table.php`, `cache`/`cache_locks` in `0001_01_01_000001_create_cache_table.php`, `jobs`/`job_batches`/`failed_jobs` in `0001_01_01_000002_create_jobs_table.php`). No new migration was needed or generated.
- [x] `php artisan migrate` — **passed** against `ellipzo_app`. Created `migrations`, `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` (9 tables, verified via `SHOW TABLES`).
- [x] Cleared cached configuration: `config:clear`, `cache:clear`, `route:clear`, `view:clear` — all passed.
- [x] Live HTTP smoke test: started `php artisan serve`, requested `/` with `curl` — **HTTP 200** (previously 500 in Task 1, per the known risk). Verified the raw response contains the correct Inertia payload (`component: "public/welcome"`, server-computed `laravelVersion: "13.21.1"`, `phpVersion: "8.4.14"`) and a real CSRF token. Confirmed via direct query that a real row was written to the `sessions` table (`SELECT COUNT(*) FROM sessions` → 1), proving `SESSION_DRIVER=database` now genuinely works end to end.
- [x] Pest + quality checks re-run against the now-working database: `./vendor/bin/pest` (2/2 pass), `./vendor/bin/pint --test` (pass), `npm run build` (pass), `npm run types` (pass), `npm run lint` (pass), `npm run format:check` (pass).

### Phase 1 — Task 3: Design tokens and layout shells (COMPLETE)

- [x] Semantic color tokens from design.md §3 implemented as Tailwind v4 `@theme` tokens in `resources/css/app.css`: full `brand-50..900` and `success-50..700` scales, `neutral-0..900`, and semantic info/warning/danger/status-success/status-neutral bg-border-text triples.
- [x] Inter configured with design.md §4's exact fallback stack, self-hosted at build time via the same `laravel-vite-plugin/fonts` `bunny()` loader Task 1 used for the placeholder font (no runtime CDN call — confirmed `public/build/assets/inter-{400,500,600,700}-*.woff2` are generated locally on every build).
- [x] Reusable typography foundation: `.text-display/h1/h2/h3/h4/body-lg/body/body-sm/label/caption` classes in `app.css`, each encapsulating design.md's mobile size + a `768px`-and-up desktop override + weight + line-height in one class (plain CSS, not Tailwind `@theme` text tokens, since a single theme token can't express a responsive breakpoint pair).
- [x] Border radius (`--radius-sm/md/lg/xl` → 6/8/12/16px) and named shadows (`--shadow-card/dropdown/modal`) override Tailwind's defaults to match design.md §6 exactly.
- [x] shadcn/ui-standard semantic `:root` variables (`--background`, `--foreground`, `--border`, `--primary`, `--focus`, etc. — design.md §19) re-exposed into the Tailwind theme via `@theme inline`, so `bg-background`/`ring-focus`-style utilities work and a future dark-mode override only touches one block.
- [x] `.focus-ring` foundation (2px outline + offset, never color-only) and a global `prefers-reduced-motion` rule collapsing all transition/animation durations — design.md §16/§17.
- [x] shadcn/ui foundation: `components.json`, `resources/js/lib/utils.ts` (`cn()`), and exactly four hand-authored primitives styled to our tokens — `button.tsx` (+ `button-variants.ts`, split out after an ESLint `react-refresh` warning), `sheet.tsx`, `dropdown-menu.tsx`, `separator.tsx`. No more than these four were added.
- [x] Four layout components in `resources/js/layouts/` (exact filenames from Architecture.md's repository structure): `public-layout.tsx`, `auth-layout.tsx`, `app-layout.tsx`, `admin-layout.tsx`. All use Inertia's persistent-layout pattern (`Page.layout = (page) => <Layout>{page}</Layout>`) rather than wrapping inline, so layout state (e.g. an open mobile menu) survives Inertia navigations.
- [x] Responsive public navigation (`components/domain/public-nav.tsx`): desktop horizontal nav, mobile hamburger opening a `Sheet` drawer, active-route highlighting, logo, and Log in/Get started actions. No notification bell (design.md: public pages never show one).
- [x] Authenticated desktop sidebar (`app-sidebar.tsx`) and mobile bottom nav (`mobile-bottom-nav.tsx`) matching design.md §10's exact item lists (Dashboard; Earn → Tasks/Surveys/Offerwalls/Submissions; Advertise → Overview/Funds/My Campaigns/Create Campaign/Review Submissions; Transactions/Referrals/Support; profile menu → Profile/Security/Notification preferences/Legal and privacy/Sign out). No separate Wallet/Withdrawals/Settings sidebar items, per design.md's explicit exclusion.
- [x] Permission-ready admin nav (`admin-nav.tsx`): accepts an optional `permissions?: string[]` prop; every item with a `requiredPermission` is filtered client-side for display only when the prop is supplied, and renders unfiltered when it is not (today — no permission system exists yet). Explicitly documented in-code and here that this is **never** an authorization boundary; every admin route must still be independently authorized server-side once built.
- [x] Lucide icons used throughout; no emoji icons; only the four shadcn primitives listed above.
- [x] 320px-and-up responsive: mobile-first Tailwind classes throughout, `lg:` breakpoint (1024px) as the mobile/desktop nav switch point per design.md §5's breakpoint table.
- [x] Accessibility foundation: skip-to-main-content links on every chrome-bearing layout, `aria-label`/`aria-current`/`aria-hidden` used correctly, icon-only buttons labelled, Radix primitives supply focus-trap/keyboard behavior for `Sheet`/`DropdownMenu` for free, reduced-motion respected globally.
- [x] Only real routes used. `welcome.tsx` now renders through `PublicLayout`. Six new minimal, honest stub routes added (`/how-it-works`, `/earn`, `/advertise`, `/help`, `/login`, `/register`) so every public-nav link actually resolves — each is just a layout + one heading + one sentence, no fake data, no forms, no login/registration logic. `app-layout.tsx`/`admin-layout.tsx` are **not** mounted on any route (no auth system exists to gate them honestly yet) — verified only via Vitest component tests and code review, not a live HTTP request. This was flagged as an explicit judgment call before implementation and is not a gap discovered afterward.
- [x] Vitest + React Testing Library set up (Architecture.md already named this as the chosen tool; it had never been installed) — `vitest.config.ts`, `resources/js/test/setup.ts`. 4 test files, 14 tests, all passing: permission-filtering logic in `admin-nav`, active-route detection in `mobile-bottom-nav`, the mobile menu open interaction in `public-nav` (via `userEvent`, real click simulation), and a smoke test mounting all four layouts. Caught two real bugs before they shipped — see the Decisions column in §12's session-log row for this task.
- [x] `tests/Feature/PublicNavigationRoutesTest.php` (Pest, dataset-driven) — asserts all seven real routes return `200` and render the expected Inertia component.
- [x] All checks pass: `npm run build`, `npm run types`, `npm run lint` (0 warnings), `npm run format:check`, `npm run test` (Vitest, 14/14), `./vendor/bin/pint --test`, `./vendor/bin/pest` (9/9, 58 assertions). Live-verified all 7 real routes via `curl` against `php artisan serve`: all return HTTP 200 with the correct Inertia `component` in the payload.
- [ ] **Honest limitation — no real browser/visual verification.** No browser automation tool (Playwright, chromium-cli) is available in this environment; installing Playwright now would mean a new, previously-unmentioned dependency plus a large browser-binary download just for a one-time check, so it was not done. Responsive behavior (320px→desktop) is verified by code review of the Tailwind breakpoint classes and by Vitest's DOM/ARIA assertions, **not** by looking at rendered pixels. Architecture.md already names Playwright for "critical journeys" — setting it up properly belongs to its own later task, not a one-off add here.
- [ ] Not in scope for Task 3 (deferred): registration/login logic, profiles, permissions/authorization enforcement, tasks, wallets, payments, Bybit — none touched, as instructed.

### Maintenance — Windows dev-script incompatibility (COMPLETE, 2026-07-24)

Not a phase deliverable; a small, explicitly-scoped fix for `composer run dev` being unusable on native Windows.

- [x] **Root cause confirmed:** `laravel/pail` requires the `pcntl` extension, which does not exist on native Windows PHP builds (Unix-only, not something `php.ini` can enable) — confirmed via `php -m` and `function_exists('pcntl_signal')`. Pail exits immediately, and `concurrently --kill-others` then tears down `serve`, `queue:listen`, and `vite` with it.
- [x] `composer run dev` (Linux/WSL) is **untouched** — byte-identical to before.
- [x] Added `composer run dev:windows` — same `concurrently`-orchestrated `php artisan serve` + `php artisan queue:listen --tries=1 --timeout=0` + `npm run dev`, same `--kill-others`, Pail simply omitted (and its color/name slot removed to keep the three remaining `-c`/`--names` lists aligned). Exact diff shown to the user before editing, per their explicit request.
- [x] `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` untouched — still `database` per Architecture.md; `.env` was not modified at all for this fix.
- [x] **Port 5173 investigated safely before any action:** `Get-NetTCPConnection` + `Get-CimInstance Win32_Process` identified the occupant as PID 13980, `node.exe` running `...\Ellipzo\node_modules\...\vite\bin\vite.js` (parent: a `cmd.exe /c vite`), both started earlier the same day — unambiguously this project's own orphaned dev-server process, not an unrelated one. Only after positive identification was it stopped (`Stop-Process`), and the port was confirmed free afterward. Vite's own automatic fallback to 5174 (already observed by the user) meant this wasn't actually blocking anything — it was cleaned up for hygiene, not because it was necessary for the fix.
- [x] **Fontaine warning resolved with the smallest change:** confirmed `optimizedFallbacks: boolean` (default `true`) is a real, documented option on `laravel-vite-plugin/fonts`' `BaseFontOptions` (checked the installed package's own `.d.ts`, not assumed) by reading `node_modules/laravel-vite-plugin/dist/fonts/types.d.ts` directly. Set `optimizedFallbacks: false` on the `bunny('Inter', ...)` call in `vite.config.ts` with an inline comment explaining why, rather than installing the optional `fontaine` package — zero new dependencies. Verified the warning no longer appears in `npm run build` output. Trade-off: fontaine's metric-optimized fallback font faces (a CLS-reduction nicety) are no longer generated; this is cosmetic, not a functional loss, and installing fontaine remains available later as a one-line reversal if wanted.
- [x] **Verified `composer run dev:windows` actually stays running** (the core requirement — `dev` does not). Started it in the background, polled until `php artisan serve` responded, confirmed via `Get-CimInstance Win32_Process`/`Get-NetTCPConnection` that the full process tree (`concurrently` → `php artisan serve` → `php -S 127.0.0.1:8000`; `php artisan queue:listen` → `queue:work`; `npm run dev` → `vite`, listening on 5173) was genuinely alive, not just that it had started — checked again after an 8-second wait with no crash. `curl http://127.0.0.1:8000/` → **HTTP 200**, correct Inertia `component: "public/welcome"` payload.
- [x] Cleanly stopped the verification run afterward. Discovered and worth recording: on Windows, `taskkill /PID <root> /T /F` did **not** fully cascade to every descendant — a second, separately-parented set of `php artisan serve`/`queue:listen`/`queue:work` processes survived the first kill and needed explicit termination by PID. This is a real Windows process-tree quirk (child processes launched through `concurrently`'s per-command shell wrappers can detach from the traceable parent chain), directly analogous to the port-5173 orphan this whole task started from. Documented here rather than silently worked around, since it may recur for whoever runs `dev:windows` day to day and has to stop it (`Ctrl+C` inside the same terminal that launched it should behave normally; it's specifically killing it from a *different* process/session, as this verification did, where the detached-tree issue shows up).
- [x] Quality checks re-run after both changes: `npm run build` (pass, no Fontaine warning), `npm run types` (pass), `npm run lint` (0 warnings), `npm run format:check` (pass), `npm run test` (Vitest, 14/14 pass), `./vendor/bin/pint --test` (pass), `./vendor/bin/pest` (9/9 pass, 58 assertions). `composer validate` confirmed `composer.json` stayed valid JSON.
- [ ] Not addressed (out of scope, not asked for): a `predev`/postinstall hint telling Windows users which script to run, or auto-detecting the OS to pick the right script automatically. Left as `dev` vs `dev:windows`, two explicit named scripts, exactly as specified.

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
| D-014 | **RESOLVED 2026-07-23 by user.** A pre-existing MariaDB database literally named `ellipzo` was discovered on this machine's XAMPP instance (MariaDB 10.4.32, `C:\xampp\mysql\data\ellipzo\`), containing ~44 tables (`wallets`, `wallet_transactions`, `deposits`, `withdrawals`, `campaigns`, `campaign_requirements`, `disputes`, `dispute_logs`, `dispute_messages`, `surveys`, `survey_completions`, `offerwall_completions`, `offerwall_configs`, `referrals`, `referral_codes`, `referral_commissions`, `support_tickets`, `support_ticket_replies`, `admins`, `admin_activity_logs`, `platform_settings`, `categories`, `countries`, `notifications`, `file_uploads`, `users`, and more). None of these match this Laravel scaffold's own migrations (which only define `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`). What should be done with it before Task 2 (database readiness) can safely continue? | My `CREATE DATABASE IF NOT EXISTS ellipzo` was a silent no-op — the database already existed. `php artisan migrate` failed with `SQLSTATE[42S02] ... 1932 Table 'ellipzo.migrations' doesn't exist in engine`. Read-only inspection (`information_schema.tables`) showed every pre-existing table has `table_rows/create_time/engine = NULL` — the MariaDB dictionary lists them but the storage engine can't currently read them. However, the actual `.frm`/`.ibd` files **do exist on disk** (`C:\xampp\mysql\data\ellipzo\*.ibd`, dated 2026-02-03, non-trivial sizes), identical to matching files in `C:\xampp\mysql\data_old\ellipzo\` — a sibling `data_old` directory (dated 2026-05-17) suggests a prior MariaDB data-directory reset/restore left this schema's InnoDB tablespace registration out of sync with the physical files. This reads as real prior work (the schema matches a full "micro-earning and advertising platform" domain almost exactly — likely an earlier, pre-Laravel implementation attempt), not disposable scaffolding, so it must not be dropped, overwritten, or auto-repaired without explicit authorization. | **No destructive action was ever taken against the pre-existing schema.** One diagnostic table `test_probe` was created and dropped immediately (confirmed by me, unambiguously mine, zero rows) — the only write action taken against it. **User chose: use a different database name.** The scoped MySQL user initially created (`ellipzo`@`127.0.0.1`, which had briefly held `GRANT ALL ON ellipzo.*` on the mystery database) was fully `DROP USER`-ed. A fresh, genuinely empty **`ellipzo_app`** database and a fresh **`ellipzo_app`**@`127.0.0.1` user (new generated password, `GRANT ALL ON ellipzo_app.*` only) were created instead. Verified the new user has zero access to the old `ellipzo` database (`ERROR 1044 Access denied`, confirmed). `.env` now points `DB_DATABASE=ellipzo_app`. `php artisan migrate`, a live HTTP smoke test, and the full Pest/quality-check suite all passed against `ellipzo_app`. The original mystery `ellipzo` database and its `data_old` backup remain completely untouched and unresolved for the user to investigate/recover separately — that is a **new, non-blocking** open item, not tracked as a numbered decision since no further action is required from Claude. |
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
| ~~Live app returns HTTP 500 until MySQL exists~~ **RESOLVED 2026-07-23** | — | Was: `.env` correctly sets `SESSION_DRIVER=database`/`CACHE_STORE=database`/`QUEUE_CONNECTION=database` per Architecture.md, but no database server existed, so every request 500s. Fixed in Task 2: MariaDB (D-014/§8 deviation row) + `ellipzo_app` database + `php artisan migrate` restored a working live app. Confirmed via a real HTTP request (200, correct Inertia payload) and a verified `sessions` table write. |
| `laravel/framework` requires PHP `^8.3`, but `pestphp/pest` v4 (latest stable, installed) requires PHP `^8.4` | Low | Dev-only constraint (Pest is `require-dev`, never ships to production), and the installed local PHP is already 8.4.14, so it does not block this machine. Flagging because Architecture.md states "PHP 8.3+" as the floor — a contributor/CI runner on PHP 8.3 exactly could install the app but not run the Pest suite. No action taken; noted for awareness. |
| `gd` and `intl` PHP extensions disabled locally | Low | Not required for Task 1. `gd` is needed for Phase 3 (proof-image dimension validation). Enable before starting that phase. |
| ~~`composer run dev` unusable on native Windows (Pail needs `pcntl`, missing on Windows)~~ **RESOLVED 2026-07-24** | — | `pcntl` cannot be enabled on native Windows PHP (Unix-only). Fixed by adding `composer run dev:windows` (Pail omitted); `dev` itself is unchanged for Linux/WSL use. Verified to stay running end to end. |
| Windows process-tree cleanup for `dev`/`dev:windows` can leave detached descendants | Low | Observed during this session's own verification: `taskkill /PID <root> /T /F` did not cascade to every `concurrently`-spawned child. Normal `Ctrl+C` in the launching terminal should behave correctly; stopping it from a different session/process may require finding and killing `php.exe`/`node.exe` PIDs individually (`Get-CimInstance Win32_Process`). No code change made for this — documented as an operational note. |
| **Local database engine is MariaDB 10.4.32, not MySQL 8** | Medium | User-approved deviation (2026-07-23) for local dev only. Architecture.md still mandates MySQL 8; reconcile before staging/production (either install real MySQL 8 there, or formally update Architecture.md if MariaDB is acceptable long-term — that decision has not been made). |
| **Pre-existing, currently-inaccessible `ellipzo` database with an unrelated ~44-table schema still sits untouched on this machine** | Medium (no longer blocking) | See D-014 — resolved by using `ellipzo_app` instead, so this Laravel app no longer depends on it. The old `ellipzo` database, its `data_old\ellipzo\` backup, and the sibling `ellipzo_db` database (noticed but not investigated) are still sitting on disk, apparently containing a prior implementation attempt with real data, currently inaccessible due to an InnoDB registration mismatch. Do not run migrations, drop, or repair any of them without explicit user authorization — this is now purely the user's item to act on whenever they choose, not a Claude task blocker. |

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

## 8b. Task 3 Dependency Decisions

All pre-approved by Architecture.md §19 (`lucide-react`, "shadcn/ui components copied into the repository," and the Vitest/RTL testing stack are named explicitly); none required asking. Versions are exact-resolved from `package-lock.json` as of 2026-07-23.

| Package | Version | Reason |
| --- | --- | --- |
| `lucide-react` | 1.26.0 | Architecture.md mandates Lucide for icons. |
| `clsx`, `tailwind-merge`, `class-variance-authority`, `@radix-ui/react-slot` | 2.1.1 / 3.6.0 / 0.7.1 / 1.3.1 | The unavoidable foundation shadcn/ui's `Button` primitive is built on (className merging + variant authoring + `asChild` support). |
| `@radix-ui/react-dialog` | 1.1.21 | Underlies the shadcn `Sheet` primitive (mobile nav drawer). |
| `@radix-ui/react-dropdown-menu` | 2.1.22 | Underlies the shadcn `DropdownMenu` primitive (sidebar profile menu). |
| `@radix-ui/react-separator` | 1.1.13 | Underlies the shadcn `Separator` primitive. |
| `vitest`, `@vitejs/plugin-react` (already installed, reused) | 4.1.10 | Architecture.md already names Vitest as the component-test tool; never installed until this task, which is the first with real interactive component logic worth unit-testing. |
| `@testing-library/react`, `@testing-library/jest-dom`, `@testing-library/user-event` | 16.3.2 / 7.0.0 / 14.6.1 | Architecture.md names "React Testing Library" explicitly alongside Vitest. |
| `jsdom` | 29.1.1 | Vitest's browser-like DOM environment for component tests (no real browser in this environment). |
| `@types/node` | 26.1.1 | Required peer for Vitest's config/type surface; wasn't previously needed since nothing touched Node APIs in-app before. |

**Deliberately not added:** `tailwindcss-animate` / `tw-animate-css` — the first draft of `sheet.tsx` used `animate-in`/`slide-in-from-*` utility class names that actually require one of these plugins; caught before finalizing and rewritten using native Tailwind v4 data-attribute variants (`data-[state=open]:opacity-100`, `transition-transform`) instead, so no extra dependency was needed. **Playwright / chromium-cli** — considered for real browser verification of this task's work, not installed; see the Task 3 checklist in §6 for the honest limitation this leaves.

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

Phase 1 Tasks 1–3 (foundation scaffold, database readiness, design tokens + layout shells) are all complete. Three independent threads remain open, none blocking the others:

**A. Old `ellipzo` database cleanup** (purely the user's item, whenever convenient):

1. Decide what to do with the pre-existing `ellipzo` database, its `data_old\ellipzo\` backup, and the sibling `ellipzo_db` database noticed but not investigated (§8 risk row). Options remain the same as presented in-session: investigate/repair with a backup first, confirm it's disposable and drop it, or simply leave it alone indefinitely since this Laravel app no longer depends on it.

**B. Phase 0 baseline decisions** (not blocking further Phase 1 foundation work, but blocking before real financial/legal-sensitive phases):

1. Ask the user for the unresolved decisions D-001 through D-012 in small groups.
2. Update this file with confirmed answers.
3. Mark Phase 0 complete only after approval.

**C. Phase 1 continuation** (recommended immediate next task):

1. Registration and login (email/password + Google Socialite), email verification, password reset, secure sessions/logout — the next Phase 1 deliverable per `docs/phases.md`. This is also what finally lets `app-layout.tsx`/`admin-layout.tsx` be mounted on real, honestly-gated routes instead of remaining component-only.
2. Profile (username, DOB, country, locale, timezone), legal consent versioning, country capabilities with deny-by-default payment flags, staff roles/permissions and the admin shell round out the rest of Phase 1 per `docs/phases.md`'s deliverables list — likely more than one further bounded task.
3. Still do not add wallet balances or Bybit Pay (Phase 2/5/6 boundaries).
4. Use the Small-Task Prompt Template in `docs/phases.md` §14 to scope the next task narrowly, as this session did for Tasks 1–3.
5. Reconcile the MariaDB-vs-MySQL-8 deviation (§8) before or alongside this work — at minimum, decide whether to formally update `Architecture.md` or plan to install real MySQL 8 before staging.
6. Consider `/run-skill-generator` once a real authenticated page exists, to capture a working "launch + log in + screenshot" recipe as a project skill — this session had no login flow yet to drive, and no browser tooling was available regardless (§8b).

## 12. Session Log

| Date | Phase | Summary | Files | Tests | Result |
| --- | --- | --- | --- | --- | --- |
| 2026-07-23 | 0 | Created and consistency-checked the complete product, architecture, rules, phases/prompts, design, and memory baseline. Recorded Bybit Pay priority and Nepal live-payment restriction. | Six documentation files | File/fence/constraint checks passed; no application tests applicable | Phase 0 in progress |
| 2026-07-23 | 1 (Task 1) | Inspected environment (PHP 8.4.14, Composer 2.9.5, Node 24.11.0, npm 11.6.1, Git 2.52.0, no MySQL server, ext-zip/gd/intl disabled, ext-bcmath/pdo_mysql enabled). Backed up `docs/` + `CLAUDE.md` to scratchpad with checksums. Ran `composer create-project laravel/react-starter-kit` into a scratchpad temp dir to verify the resolved Laravel version before touching the repo root. Discovered it resolves `laravel/framework v12.64.0` (Laravel 12), not Laravel 13. Confirmed via `composer show -a` that Laravel 13 exists on Packagist (up to v13.21.1) but the starter kit's latest stable tag v1.0.1 pins `^12.0`; only its `dev-main` branch targets `^13.17`. **Stopped before any write to the project root** per explicit user instruction to stop and report if Laravel 13 does not resolve. | None in project root; scratchpad-only: `docs-backup-phase1-task1/`, `ellipzo-scaffold/` (Laravel 12 probe, discarded), `probe/composer.json.main` | No application tests run (blocked before scaffold merge) | **Blocked — awaiting user decision on D-013** |
| 2026-07-23 | 1 (Task 2) | Started database-readiness task. Checked for MySQL 8: not installed, but found XAMPP's MariaDB 10.4.32 already running on port 3306 (no admin/GUI action needed). Searched for a separate genuine MySQL 8 install (Windows services, PATH, alternate ports, `Program Files`) — none found. **Stopped and asked the user** whether to use the running MariaDB, install real MySQL 8 alongside it, or pause — user chose to use MariaDB and record it as a documented deviation. Verified root connects with the standard XAMPP empty password. Generated a random password (never echoed to any command output) and ran `CREATE DATABASE IF NOT EXISTS ellipzo`, `CREATE USER 'ellipzo'@'127.0.0.1'`, `GRANT ALL ON ellipzo.*`, then wrote `DB_USERNAME=ellipzo`/`DB_PASSWORD=<generated>` into `.env` via `sed` (password never appeared in terminal output). Verified the scoped user connects. Ran `php artisan migrate` — **failed**: `SQLSTATE[42S02] ... 1932 Table 'ellipzo.migrations' doesn't exist in engine`. Investigated (read-only): `CREATE DATABASE IF NOT EXISTS` had been a silent no-op because a database named `ellipzo` already existed, containing ~44 tables unrelated to this Laravel scaffold (see D-014). `information_schema.tables` showed every pre-existing table with `table_rows`/`create_time`/`engine` all `NULL` (dictionary knows about them, storage engine can't read them) — but confirmed via `ls` that the actual `.frm`/`.ibd` files physically exist on disk in both `C:\xampp\mysql\data\ellipzo\` and a sibling `C:\xampp\mysql\data_old\ellipzo\` (same filenames, same 2026-02-03 timestamps), indicating a prior MariaDB data-directory reset left this schema's InnoDB registration out of sync with real files that likely still hold data. Created one diagnostic table `test_probe` while investigating (0 rows) and dropped it immediately after — the only write action taken against the pre-existing schema. **Stopped all further database action and recorded D-014** rather than dropping, repairing, or migrating over the mystery database. | `.env` (DB_USERNAME/DB_PASSWORD only — password never logged), `docs/memory.md` | `php artisan migrate` — **failed** (expected outcome given the discovery; not a scaffold defect). No other checks run (build/types/lint/format/Pint/Pest untouched since Task 1, still assumed passing at commit `55862bd`). | Used MariaDB instead of MySQL 8 for local dev (user decision, documented deviation). Created a dedicated scoped `ellipzo` MySQL user rather than using `root` in `.env`, for better local security hygiene. Did not attempt any repair/recovery/drop of the mystery database without authorization. | **Blocked on D-014** — a pre-existing, apparently-real database occupies the name this task needs. MySQL-engine deviation (MariaDB vs MySQL 8) also needs eventual reconciliation (non-blocking). | See §11 for the exact next step once the user decides D-014. |
| 2026-07-23 | 1 (Task 2, resolution) | **D-014 resolved by user**: use a different database name rather than touch the pre-existing `ellipzo` database. Confirmed `ellipzo_app` didn't also already exist (`SHOW DATABASES`) before creating it — learned from the earlier `IF NOT EXISTS` surprise. Fully removed the old `ellipzo`@`127.0.0.1` MySQL user (`DROP USER`) rather than merely repointing it, since it had briefly held `GRANT ALL` on the mystery database. Created a fresh `ellipzo_app` database (`utf8mb4`/`utf8mb4_unicode_ci`) and a fresh `ellipzo_app`@`127.0.0.1` user with a newly generated password (never echoed to any command output), scoped only to `ellipzo_app.*`. Verified the new user can connect to `ellipzo_app` and gets `ERROR 1044 Access denied` when attempting to touch the old `ellipzo` database — confirming full isolation. Updated `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) via `sed`. Ran `php artisan migrate` — **passed**, created 9 tables (`migrations`, `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`), verified via `SHOW TABLES`. Cleared all caches (`config:clear`, `cache:clear`, `route:clear`, `view:clear`). Ran a real live smoke test: `php artisan serve` + `curl /` → **HTTP 200** (previously 500). Inspected the raw response: correct Inertia JSON payload (`component: "public/welcome"`, `laravelVersion: "13.21.1"`, `phpVersion: "8.4.14"`), a real CSRF token, and confirmed via direct SQL query that a session row was actually written (`SELECT COUNT(*) FROM sessions` → 1) — proving `SESSION_DRIVER=database` genuinely works now, not just that the page loaded. Re-ran the full quality suite against the now-working app: Pest (2/2 pass), Pint (pass), `npm run build`/`types`/`lint`/`format:check` (all pass, unchanged from Task 1). | `.env` (`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` updated — password never logged), `docs/memory.md` | `php artisan migrate` — pass (9 tables created). Live smoke test — **HTTP 200**, correct Inertia payload, verified real session write. `./vendor/bin/pest` — 2/2 pass. `./vendor/bin/pint --test` — pass. `npm run build` — pass. `npm run types` — pass. `npm run lint` — pass. `npm run format:check` — pass. | Used `ellipzo_app` instead of `ellipzo` as the database name (D-014, user decision) — the safest option, left the mystery database and `data_old` entirely untouched. Fully removed rather than repointed the MySQL user that had briefly touched the mystery database, for defense in depth. | **None blocking.** Non-blocking carryovers: old `ellipzo`/`ellipzo_db` databases still unresolved (now purely optional cleanup, not a task blocker — §8); MariaDB-vs-MySQL-8 deviation still needs reconciling before staging/production (§8); Phase 0 decisions D-001–D-012 still open. | **Phase 1 Task 2 complete.** See §11 for the next task. |
| 2026-07-23 | 1 (Task 3) | Read all six docs + `docs/memory.md` fresh; confirmed unchanged since last read. Presented an implementation plan with two explicit judgment calls (public-nav stub pages for real routes; app/admin layouts staying unmounted with no live route) before writing code; user approved as scoped. Installed shadcn/ui foundation packages + Lucide + Vitest/RTL/jsdom (all pre-approved by Architecture.md; full list and reasons in §8b). Built the full design-token system in `app.css`: brand/success/neutral color scales, semantic status triples, radius/shadow overrides, shadcn-style `:root` semantic vars re-exposed via `@theme inline`, a plain-CSS responsive typography scale (10 classes covering design.md's full type table), `.focus-ring`, and a global `prefers-reduced-motion` rule. Swapped the placeholder "Instrument Sans" font for Inter via the same self-hosting `bunny()` loader already in use — confirmed at build time that `inter-{400,500,600,700}-*.woff2/woff` files are generated locally, not fetched from a runtime CDN. Hand-authored four shadcn primitives (`button`, `sheet`, `dropdown-menu`, `separator`) since the `shadcn` CLI wasn't invoked; caught mid-build that the first draft of `sheet.tsx` used `tailwindcss-animate`-only class names and rewrote it with native Tailwind v4 data-attribute variants to avoid an unplanned dependency. Built four layout components using Inertia's persistent-layout pattern, and four navigation components (`public-nav`, `app-sidebar`, `mobile-bottom-nav`, `admin-nav`) using the exact route paths and nav item lists already documented in Architecture.md/design.md. Wired `welcome.tsx` to `PublicLayout` and added six minimal, honest stub routes/pages so every public-nav link is real; deliberately did not add `/legal/terms`/`/legal/privacy` links that would have been additional unplanned non-working routes. Set up Vitest + RTL (`vitest.config.ts`, `resources/js/test/setup.ts`) and wrote 4 test files (14 tests) targeting the actual logic in this task: `admin-nav`'s permission-filtering, active-route detection, and the mobile menu's real open interaction via `userEvent`. The tests caught two genuine bugs before they shipped: (1) `Button`'s `{isLoading && <Loader2/>}{children}` produced a 2-element children array even when not loading, which crashes Radix `Slot`'s single-child requirement whenever `asChild` is used — fixed by restructuring to always pass exactly one child expression; (2) React Testing Library's cross-test DOM cleanup wasn't running because Vitest's `globals` mode was deliberately left off, so renders were accumulating across tests in the same file — fixed with an explicit `afterEach(cleanup)` in the test setup file rather than flipping on globals mode. Added `tests/Feature/PublicNavigationRoutesTest.php` (Pest, dataset-driven) covering all seven real routes. Ran the full check suite twice (before and after the two bug fixes): build, `tsc --noEmit`, ESLint, Prettier, Pint, Pest, Vitest. Live-verified all seven routes via `curl` against `php artisan serve` (all 200, correct Inertia `component` in each payload). Attempted real browser verification via the `/run` skill; no project skill existed yet, `chromium-cli` wasn't available, and Playwright wasn't installed — declined to add it silently since it would be a new, previously-unmentioned dependency plus a large browser download for a one-time check, and reported this honestly as a limitation rather than claiming visual verification that didn't happen. | New: `components.json`, `resources/js/lib/utils.ts`, `resources/js/components/ui/{button,button-variants,sheet,dropdown-menu,separator}.tsx`, `resources/js/types/nav.ts`, `resources/js/components/domain/{public-nav,app-sidebar,mobile-bottom-nav,admin-nav}.tsx` (+ 3 matching `.test.tsx` files), `resources/js/layouts/{public-layout,auth-layout,app-layout,admin-layout}.tsx` (+ `layouts.test.tsx`), `resources/js/pages/public/{how-it-works,earn,advertise,help}.tsx`, `resources/js/pages/auth/{login,register}.tsx`, `vitest.config.ts`, `resources/js/test/setup.ts`, `tests/Feature/PublicNavigationRoutesTest.php`. Edited: `resources/css/app.css`, `vite.config.ts`, `resources/js/pages/public/welcome.tsx`, `routes/web.php`, `package.json`/`package-lock.json`. No PHP dependency changes (`composer.json`/`composer.lock` untouched); no migrations. | `npm run build` — pass. `npm run types` — pass. `npm run lint` (0 warnings) — pass, after fixing the `button.tsx` `react-refresh` warning by extracting `button-variants.ts`. `npm run format:check` — pass, after one `npm run format` auto-fix pass across 11 files. `npm run test` (Vitest) — 14/14 pass, after fixing the two bugs above (10 tests initially failed). `./vendor/bin/pint --test` — pass. `./vendor/bin/pest` — 9/9 pass, 58 assertions (2 pre-existing + 7 new dataset cases). Live `curl` smoke test — all 7 routes 200 with correct Inertia component. | Hand-authored shadcn primitives instead of running the `shadcn` CLI (no interactive/registry dependency). Used plain responsive CSS classes for the typography scale rather than Tailwind `@theme` text tokens, since a single theme token can't express a mobile/desktop size pair. Added 6 honest stub pages so public nav is fully real rather than leaving non-working links or omitting nav items. Left `app-layout`/`admin-layout` unmounted from any route rather than fake-gating a dashboard/admin page without a real auth system — verified via Vitest + code review only. Did not add `tailwindcss-animate` or Playwright even though each would have made one thing easier, to avoid unplanned dependencies. | None blocking. Carried over, unchanged: old `ellipzo`/`ellipzo_db` databases still unresolved (§8, user's item); MariaDB-vs-MySQL-8 deviation still needs reconciling before staging (§8); Phase 0 decisions D-001–D-012 still open. New, non-blocking: no real browser/visual verification was possible in this environment (§8b) — code review + Vitest DOM assertions substitute for it; `app-layout`/`admin-layout` remain unreachable via any live URL until real authentication exists. | **Task 3 complete** |
| 2026-07-24 | 1 (maintenance) | Fixed `composer run dev` being unusable on native Windows. Confirmed root cause first: `php -m` and `function_exists('pcntl_signal')` both confirm `pcntl` is not loaded (it's Unix-only, not a Windows PHP build option at all), so `laravel/pail` exits immediately and `concurrently --kill-others` tears down `serve`/`queue:listen`/`vite` with it. Showed the exact planned `composer.json` diff to the user before editing, as explicitly requested. Added `composer run dev:windows` (same `concurrently` orchestration, Pail omitted, `-c`/`--names` lists kept aligned) directly after the untouched `dev` script; ran `composer validate` to confirm the JSON stayed valid. Investigated port 5173 safely before touching anything: `Get-NetTCPConnection` + `Get-CimInstance Win32_Process` identified the occupant as PID 13980 (`node.exe` running this exact project's `vite/bin/vite.js`, parent `cmd.exe /c vite`, both started earlier the same day) — positively confirmed as this project's own orphaned process, not an unrelated one, only then stopped. Fixed the Fontaine warning with the smallest change: read `node_modules/laravel-vite-plugin/dist/fonts/types.d.ts` directly to confirm `optimizedFallbacks: boolean` is a real documented option before using it (did not guess), then set `optimizedFallbacks: false` on the `bunny('Inter', ...)` call with an inline comment — zero new dependencies, warning confirmed gone from `npm run build` output. Verified `composer run dev:windows` actually stays running: started it backgrounded, polled until `php artisan serve` responded, then used `Get-CimInstance Win32_Process`/`Get-NetTCPConnection` (not just log output) to confirm the full process tree — `concurrently` → `serve`/`queue:listen`/`vite`, port 8000 and 5173 both listening — was genuinely alive, re-checked again after an 8-second wait with no crash. `curl http://127.0.0.1:8000/` → HTTP 200, correct Inertia `component: "public/welcome"` payload. While cleaning up the verification run, discovered and documented a real Windows quirk: `taskkill /PID <root> /T /F` did not cascade to every `concurrently`-spawned descendant — a second, separately-parented set of `serve`/`queue:listen`/`queue:work` processes survived the first kill and needed explicit termination by PID. Recorded this as an operational note (§8) rather than silently working around it, since it may recur for whoever stops `dev:windows` from outside its own launching terminal. Did not touch `.env`, `SESSION_DRIVER`, `CACHE_STORE`, or `QUEUE_CONNECTION`. Did not start any new feature work. | Edited: `composer.json` (new `dev:windows` script only), `vite.config.ts` (`optimizedFallbacks: false` + comment), `docs/memory.md`. No new files, no dependency changes, no migrations. | `composer validate` — pass (valid JSON). `npm run build` — pass, Fontaine warning gone. `npm run types` — pass. `npm run lint` (0 warnings) — pass. `npm run format:check` — pass. `npm run test` (Vitest) — 14/14 pass. `./vendor/bin/pint --test` — pass. `./vendor/bin/pest` — 9/9 pass, 58 assertions. Live verification of `dev:windows`: full process tree confirmed alive via `Get-CimInstance`/`Get-NetTCPConnection` (not log-output alone), `curl` → HTTP 200 with correct Inertia payload, re-checked after 8s with no crash — directly demonstrating the fix, since `dev` itself would have already died by this point. | Used `optimizedFallbacks: false` over installing the optional `fontaine` package — smaller change, zero new dependencies, cosmetic trade-off only (loses CLS-reduction font-fallback metrics, not documented as blocking anything). Positively identified the port-5173 occupant as this project's own process before stopping it, per the explicit "don't blindly terminate an unrelated process" instruction. Documented the Windows process-tree cascade quirk found during cleanup rather than treating it as a one-off annoyance to ignore. | None blocking. New, low-severity, documented in §8: stopping `dev`/`dev:windows` from outside its own launching terminal may require killing individual `php.exe`/`node.exe` PIDs due to incomplete Windows process-tree cascade. Unchanged carryovers: old `ellipzo`/`ellipzo_db` databases still unresolved (user's item); MariaDB-vs-MySQL-8 deviation still needs reconciling before staging; Phase 0 decisions D-001–D-012 still open. | **Maintenance fix complete** |
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
