# TAD v0.1 → v0.2 — what changed and why

Applied 2026-09-16 against the findings in `validation-report.md`.
Backup of v0.1: `/tmp/.../scratchpad/TAD.backup.md` (session scratchpad).

## 1. Stack repinned off end-of-life versions
| Was | Now | Why |
|---|---|---|
| PHP 8.3 / Laravel 11 (D-03) | **PHP 8.4 / Laravel 13** | Laravel 11 security support ended 2026-03-12 |
| Nuxt 3 (D-13) | **Nuxt 4** | Nuxt 3 reached EOL 2026-07-31 |
| Redis 7 | Redis 7 **or Valkey**, flagged | 2024 relicensing made this an inherited choice, not a decision |
| targetSdk 32, no rationale (D-14) | Unchanged, **rationale added** | OTA-only distribution (new D-16) makes it valid; Play's floor does not apply |

The §3.1 argument for Laravel over Nest.js was left intact — it was about framework fit for a
small team and survives the version change untouched.

## 2. MVP scope set — narrowest shippable definition wins
New **§1.3** states the rule and the resolution table. New decisions:

- **D-16** POS distribution is OTA-only
- **D-17** Sale scope = PAD's "Simple Sale and Invoice Experience"
- **D-18** Product catalog, inventory, cart, discount engine — out of MVP
- **D-19** RFM / behavioural segmentation — out of MVP
- **D-20** Bot notifications — out of MVP (reverses D-12)
- **D-21** Marketplace — out of MVP
- **D-10** rewritten: tax is *not built by Market in MVP* rather than *removed from Market's
  scope* — the earlier wording unilaterally deleted PAD §2.22's bounded context

B2B/B2C was resolved as a packaging concern carried in Offering/Entitlement, not an
architectural axis — so it needs no structural change.

Everything cut stays **defined in the data model**. Cutting was a delivery decision, not a design one.

## 3. Delivery plan made survivable
- Wave 2: weeks 13–20 → **13–18** (bots removed)
- Wave 3: weeks 21–26 → **19–22** (marketplace removed)
- **Weeks 23–26 are now unallocated contingency** — ~15%, the first buffer the plan has had
- **A-11 added**: team composition, the assumption the entire plan rests on and which v0.1 never stated
- **A-12 added**: PAD is authoritative over PRD on MVP scope
- **A-04 promoted to O-07** with a week-2 date. v0.1 called O-01/O-02 "the only true external
  blockers"; that was wrong — a fake adapter cannot substitute for a capability missing in a
  real upstream system
- Risk register gains: team capacity below A-11, no Payment Core inquiry endpoint, scope
  re-expansion from the PRD, stack drifting back onto EOL versions

## 4. Boundary gaps closed
- **§7.1** — cross-module references are logical ids, **no database FK across a module boundary**
  (a real FK there is what blocks the service extraction §4 promises); intra-module refs keep
  real FKs; **one writing module per table**; read models written only by named projectors
- **§5.6 (new)** — tenant context propagation into queued jobs and the outbox relay, with the
  §5.4 platform bypass role explicitly denied to workers. This closed the most dangerous gap
  found: the *convenient* fix for RLS returning empty in a job is the bypass role, which would
  have made an audited escape hatch routine
- **§16.4** — RLS isolation test must also run inside a job; static check that no FK crosses a
  module boundary

## Still open — not architecture's to answer
A-11 (team), O-07 (Payment Core inquiry), stakeholder sign-off on §1.3, and re-export of the PRD.
