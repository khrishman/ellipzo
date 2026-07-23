# Ellipzo Design System

**Document version:** 1.0  
**Status:** Visual source of truth  
**Last updated:** 2026-07-23

## 1. Design Direction

Ellipzo should feel trustworthy, practical, calm, and modern. It is a work-and-advertising platform, not a speculative crypto product.

The interface must avoid:

- Neon colors.
- Gradients.
- Glassmorphism.
- Glowing effects.
- Casino-like visuals.
- Busy coin imagery.
- Excessive animation.
- Huge marketing headings that reduce usable space.
- Fake urgency such as “earn instantly” or “guaranteed income.”

Primary visual characteristics:

- Clean white and light-neutral surfaces.
- Indigo for primary product actions.
- Emerald for success and positive available states.
- Strong typography and generous spacing.
- Clear tables, cards, filters, and status badges.
- Mobile-first task interactions.
- Dense but readable advertiser and staff dashboards.

## 2. Brand Foundation

### Product name

**Ellipzo**

### Product description

A micro-earning and advertising platform where eligible users complete legitimate digital work and can fund their own campaigns from the same account.

### Voice

- Direct.
- Human.
- Clear.
- Respectful.
- Transparent about status, fees, reviews, and delays.
- Never hype-driven.

Preferred:

> Your proof was submitted and is waiting for review.

Avoid:

> Amazing! Free money is on the way!

Preferred:

> This withdrawal needs a manual review. Your funds remain on hold while we check it.

Avoid:

> Something went wrong.

## 3. Color System

### Core brand colors

| Token | Hex | Use |
| --- | --- | --- |
| `brand-50` | `#EEF2FF` | Selected backgrounds |
| `brand-100` | `#E0E7FF` | Hover/soft accent |
| `brand-200` | `#C7D2FE` | Focus support |
| `brand-300` | `#A5B4FC` | Decorative/disabled accent |
| `brand-400` | `#818CF8` | Secondary accent |
| `brand-500` | `#6366F1` | Secondary brand |
| `brand-600` | `#4F46E5` | Primary action |
| `brand-700` | `#4338CA` | Primary hover |
| `brand-800` | `#3730A3` | Primary active |
| `brand-900` | `#312E81` | Dark brand text |

### Success colors

| Token | Hex | Use |
| --- | --- | --- |
| `success-50` | `#ECFDF5` | Success background |
| `success-100` | `#D1FAE5` | Soft highlight |
| `success-500` | `#10B981` | Success and green CTA |
| `success-600` | `#059669` | Success hover |
| `success-700` | `#047857` | Success text |

### Neutral colors

| Token | Hex | Use |
| --- | --- | --- |
| `neutral-0` | `#FFFFFF` | Primary surface |
| `neutral-50` | `#F8FAFC` | Page background |
| `neutral-100` | `#F1F5F9` | Subtle fill |
| `neutral-200` | `#E2E8F0` | Borders |
| `neutral-300` | `#CBD5E1` | Disabled border |
| `neutral-400` | `#94A3B8` | Placeholder |
| `neutral-500` | `#64748B` | Secondary text |
| `neutral-600` | `#475569` | Body-muted text |
| `neutral-700` | `#334155` | Body text |
| `neutral-800` | `#1E293B` | Heading text |
| `neutral-900` | `#0F172A` | Strong text |

### Semantic colors

| Meaning | Background | Border | Text/Icon |
| --- | --- | --- | --- |
| Info | `#EFF6FF` | `#BFDBFE` | `#1D4ED8` |
| Success | `#ECFDF5` | `#A7F3D0` | `#047857` |
| Warning | `#FFFBEB` | `#FDE68A` | `#B45309` |
| Danger | `#FEF2F2` | `#FECACA` | `#B91C1C` |
| Neutral | `#F8FAFC` | `#E2E8F0` | `#475569` |

### Color rules

- Primary action uses `brand-600`.
- Positive financial availability may use emerald, but do not color every amount green.
- Withdrawal, deletion, rejection, and suspension actions use danger only when destructive.
- Warning means attention is needed, not failure.
- Do not rely on color alone; pair it with a label or icon.
- Text and controls must meet WCAG 2.2 AA contrast.
- Do not create gradients from these tokens.

## 4. Typography

### Font family

Use **Inter** for the product, marketing pages, and admin console.

Fallback:

```css
font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
  "Segoe UI", sans-serif;
```

Load it locally or through an approved optimized delivery method. The interface must remain usable if the web font fails.

### Type scale

| Style | Mobile | Desktop | Weight | Line height |
| --- | --- | --- | --- | --- |
| Display | 36px | 52px | 700 | 1.08 |
| H1 | 30px | 36px | 700 | 1.15 |
| H2 | 24px | 30px | 650–700 | 1.2 |
| H3 | 20px | 24px | 600 | 1.3 |
| H4 | 18px | 20px | 600 | 1.35 |
| Body large | 17px | 18px | 400 | 1.6 |
| Body | 15px | 16px | 400 | 1.55 |
| Body small | 13px | 14px | 400 | 1.5 |
| Label | 13px | 14px | 500–600 | 1.4 |
| Caption | 12px | 12px | 500 | 1.4 |

Rules:

- Use sentence case for headings and buttons.
- Avoid all caps except short data labels where accessibility remains clear.
- Use tabular numerals for balances, amounts, IDs, and metrics.
- Financial amounts should be easy to scan but not oversized.
- Limit marketing paragraph width to approximately 65 characters.
- Product pages prioritize information density over large decorative type.

## 5. Spacing and Layout

Use a 4px base grid.

| Token | Value |
| --- | --- |
| `space-1` | 4px |
| `space-2` | 8px |
| `space-3` | 12px |
| `space-4` | 16px |
| `space-5` | 20px |
| `space-6` | 24px |
| `space-8` | 32px |
| `space-10` | 40px |
| `space-12` | 48px |
| `space-16` | 64px |
| `space-20` | 80px |

### Containers

- Marketing max width: `1200px`.
- Product max width: `1440px`.
- Reading content max width: `760px`.
- Page horizontal padding: 16px mobile, 24px tablet, 32px desktop.
- Dashboard grid gap: 16px mobile, 20–24px desktop.

### Responsive breakpoints

Use Tailwind defaults unless implementation needs a documented exception:

- `sm`: 640px.
- `md`: 768px.
- `lg`: 1024px.
- `xl`: 1280px.
- `2xl`: 1536px.

Design and test down to 320px.

## 6. Shape and Elevation

### Border radius

| Component | Radius |
| --- | --- |
| Small input/badge | 6px |
| Button/input | 8px |
| Card/dropdown | 12px |
| Modal/large panel | 16px |
| Avatar | Full |

Do not make every card pill-shaped.

### Borders

- Default: 1px `neutral-200`.
- Strong separator: 1px `neutral-300`.
- Focus: 2px visible brand ring with offset.
- Selected card: brand border plus subtle brand-50 background.

### Shadows

Use shadows sparingly:

- Card: `0 1px 2px rgba(15, 23, 42, 0.05)`.
- Dropdown: `0 8px 24px rgba(15, 23, 42, 0.10)`.
- Modal: `0 20px 50px rgba(15, 23, 42, 0.18)`.

Avoid heavy floating shadows on every surface.

## 7. Iconography

- Use `lucide-react`.
- Standard icon size: 18–20px.
- Compact table/action icon: 16px.
- Empty-state icon: 32–40px.
- Icons need accessible labels when the meaning is not visible in text.
- Do not use emojis as interface icons.
- Do not mix icon families.
- The Bybit brand mark may be used only according to its brand/merchant terms.

## 8. Buttons

### Variants

#### Primary

- Indigo background.
- White text.
- Use for the single main action in a section.

#### Success

- Emerald background.
- White text.
- Use selectively for positive creation actions, including “Create New Ticket” as previously specified.

#### Secondary

- White background.
- Neutral border.
- Neutral-700 text.

#### Ghost

- Transparent.
- Use for low-emphasis controls.

#### Danger

- Red background for irreversible/high-impact confirmation.
- Red text/soft background for lower-emphasis destructive action.

### Sizing

| Size | Height | Horizontal padding |
| --- | --- | --- |
| Small | 32px | 12px |
| Default | 40px | 16px |
| Large | 48px | 20px |

Rules:

- Minimum touch target is 44×44px when practical.
- Loading buttons keep their width and show a spinner plus label.
- Disable repeated submissions during processing.
- Disabled controls include an explanation when the reason is not obvious.
- Button text uses clear verbs: “Submit proof,” “Create campaign,” “Request withdrawal.”

## 9. Forms

- Labels appear above fields.
- Required fields use text or an accessible indicator, not color alone.
- Help text appears before error text.
- Field errors appear directly below the affected field.
- Preserve safe input after validation errors.
- Never preserve passwords, 2FA codes, or secret values.
- Money inputs show currency and allowed precision.
- Destructive confirmations restate impact.
- Multi-step forms show current step and remaining steps.
- Do not hide critical requirements in tooltips.

### Input states

- Default.
- Hover.
- Focus with visible brand ring.
- Filled.
- Disabled.
- Read-only.
- Error.
- Success only when useful.

## 10. Navigation

### Public navigation

- Logo.
- Earn.
- Advertise.
- How It Works.
- Help.
- Log In.
- Get Started.

Do not show the logged-in notification bell on public pages.

### Authenticated desktop navigation

Use a left sidebar:

- Dashboard.
- Earn.
  - Tasks.
  - Surveys.
  - Offerwalls.
  - Submissions.
- Advertise.
  - Overview.
  - Funds.
  - My Campaigns.
  - Create Campaign.
  - Review Submissions.
- Transactions.
- Referrals.
- Support.

Profile menu:

- Profile.
- Security.
- Notification preferences.
- Legal and privacy.
- Sign out.

Do not add separate Wallet, Withdrawals, or Settings items to the main sidebar when those functions fit better under balances/profile. A withdrawal action can live on the earning balance/transactions area.

### Authenticated mobile navigation

Bottom navigation:

- Home.
- Earn.
- Advertise.
- Activity.
- Menu.

Use labels with icons. Keep major actions reachable with one thumb.

### Admin navigation

Admin uses a separate layout and permission-aware groups:

- Overview.
- Users.
- Campaigns.
- Submissions.
- Disputes.
- Finance.
- Risk.
- Support.
- Providers.
- Reports.
- Settings.
- Audit.

## 11. Cards and Data Display

### Balance cards

Show:

- Label.
- Formatted amount and currency.
- Available/held/reserved distinction.
- One relevant action.
- Optional short explanation.

Do not combine earning and advertising balances into one ambiguous total.

### Task cards

Show:

- Category.
- Clear title.
- Reward.
- Estimated effort only if based on a defined value.
- Country/device eligibility indicator.
- Available slots or availability status.
- Proof type.
- Primary action.

Avoid cluttering cards with the full instructions.

### Campaign cards/rows

Show:

- Name.
- Status badge.
- Spend/total budget.
- Approved/pending/target completions.
- Start/end date.
- One primary next action.

### Tables

- Use tables for multi-column desktop data.
- Convert to stacked cards on small screens.
- Sticky header only when useful.
- Right-align numeric amounts.
- Provide accessible sort labels.
- Paginate rather than rendering unbounded records.
- Row action menus contain secondary actions, not the main action.

## 12. Status System

Use a badge with icon/label where needed.

| Status type | Treatment |
| --- | --- |
| Draft/neutral | Neutral |
| Pending/review | Warning |
| Running/active | Info or success |
| Approved/paid/completed | Success |
| Paused/limited | Warning |
| Rejected/failed/suspended | Danger |
| Expired/cancelled | Neutral |
| Review required/unknown | Warning with explicit text |

Do not label an unknown payment outcome “failed.” Do not label a provider-pending payout “paid.”

## 13. Feedback States

Every data page must include:

- Loading/skeleton state.
- Empty state with a useful next action.
- Error state with correlation ID when available.
- Permission/eligibility state.
- Success feedback.
- Partial/provider-unavailable state where relevant.

### Toasts

Use toasts for brief confirmations:

- Profile updated.
- Draft saved.
- Ticket reply sent.

Do not use a toast as the only record of a financial status. Deposits, withdrawals, campaign funding, and reward results need a persistent page status/history.

### Confirmations

Require modal confirmation for:

- Campaign cancellation with fund-release consequences.
- Submission rejection.
- Withdrawal submission.
- Staff suspension.
- Ledger adjustment.
- Role/permission change.

The modal must state what happens next.

## 14. Page Patterns

### Dashboard

Mobile:

1. Earning and advertising balance cards.
2. Primary quick actions.
3. Pending items.
4. Recommended tasks.
5. Recent activity.

Desktop:

- Two- or three-column metric area.
- Main activity/task content.
- Secondary pending/notification column.

### Task detail

- Title and reward.
- Eligibility and availability.
- Requirements.
- Step-by-step instructions.
- Proof requirements.
- Start/reserve/submit action.
- Report task.
- Related policy notice.

Use a sticky action footer on mobile when it does not cover content.

### Create campaign

Use a clear multi-step flow:

1. Basics.
2. Audience.
3. Instructions and proof.
4. Budget and schedule.
5. Review and submit.

Show server-calculated:

- Reward per approved completion.
- Quantity.
- Platform fee.
- Total required reservation.
- Available advertising funds.

### Add advertising funds

- Explain that funds are for campaigns.
- Amount input.
- Limits/fees.
- Bybit Pay option.
- QR/checkout.
- Persistent status timeline.
- Clear expiry and retry rules.
- Never show success before server-confirmed payment.

### Withdrawal

- Available earning balance.
- Destination summary.
- Amount, limits, and fees.
- Security confirmation.
- Review notice.
- Persistent status timeline.
- Link to support for long-running review.

### Admin finance

- Filters before large tables.
- Status, amount, user, provider ID, internal reference, age, and risk indicator.
- Detail drawer/page with immutable timeline.
- Explicit approve/reject/reconcile actions based on permission.
- No single-click destructive or money-moving action.

## 15. Marketing Pages

### Hero

- Plain explanation of the platform.
- Primary action: “Start earning” or “Get started.”
- Secondary action: “Advertise.”
- Product UI preview rather than coin imagery.
- Visible statement that earnings require completed and approved work.

### Trust content

- How review works.
- Transparent balances and histories.
- Country availability.
- Support and dispute process.
- “Not an investment platform.”
- Payment methods shown only where enabled.

### Reference products

Ellipzo may learn from the information architecture of established task platforms, but must not copy their protected text, brand, layout, or assets.

## 16. Motion

- Default transition: 120–180ms.
- Use opacity and small position changes.
- No bouncing balances, spinning coins, or constant background motion.
- Respect `prefers-reduced-motion`.
- Skeletons should not create distracting high-contrast flashes.

## 17. Accessibility Checklist

- Semantic landmarks.
- One logical H1 per page.
- Keyboard access to menus, dialogs, tabs, and forms.
- Focus trapped and restored correctly in dialogs.
- Visible focus ring.
- Form errors announced and linked to fields.
- Icon-only buttons have accessible names.
- Table alternatives on small screens preserve relationships.
- Status is not color-only.
- Text zoom to 200% remains usable.
- Touch targets are sufficient.
- Contrast meets WCAG 2.2 AA.

## 18. Content Rules

- Use “deposit advertising funds,” not “invest.”
- Use “reward,” not “profit.”
- Use “withdraw earnings,” not “cash out gains.”
- Use “campaign,” not “scheme.”
- Explain holds, fees, and review times before confirmation.
- Avoid guaranteed speed unless operationally guaranteed.
- Avoid “instant” for provider-dependent actions.
- Dates display in the user's timezone; history may also show UTC in detail views.
- Use consistent US English initially; localization can be added later.

## 19. Tailwind Token Direction

Create semantic CSS variables and map components to them. Do not scatter raw hex codes throughout React components.

Suggested variables:

```css
:root {
  --background: #f8fafc;
  --surface: #ffffff;
  --foreground: #0f172a;
  --muted: #64748b;
  --border: #e2e8f0;
  --primary: #4f46e5;
  --primary-hover: #4338ca;
  --success: #10b981;
  --danger: #b91c1c;
  --warning: #b45309;
  --focus: #6366f1;
}
```

If dark mode is added later, define complete semantic tokens and test contrast. Do not auto-generate a partial dark theme.

## 20. Design Definition of Done

A page is visually complete when:

- It follows the tokens and layout rules here.
- It works at 320px, tablet, laptop, and wide desktop.
- Loading, empty, error, forbidden, disabled, and success states exist.
- Forms have labels, help, validation, and accessible focus.
- Statuses use consistent semantic treatment.
- No fake financial data is presented as real.
- No gradient, neon, glass, or crypto-casino styling appears.
- Keyboard and screen-reader basics are verified.
- The implementation is consistent with the rest of Ellipzo.
