# Architecture Validation Report — PayStar Market TAD

- **Document:** `Paystar Market TAD.md` (v0.1 Draft, status "Not approved", ~1,754 lines)
- **Parent:** `Paystar Market PAD.md` (v1, Ch. 1–4)
- **Sibling:** `Paystar Market PRD.md` — **unusable**, see `review-prd-blocked.md`
- **Run at:** 2026-09-16
- **Grade:** **Fair** — strong design, stale inputs

## Overall verdict

This is a well-made architecture document. The invariants are enforced rather than aspirational,
the multi-tenancy design closes the two ways RLS is normally defeated, and the payment-durability
chain (local-write-before-network, idempotency, recovery, reconciliation) is careful work with the
authority question properly pinned. Most of what a reviewer would attack, it has already closed.

Its defects are not in reasoning. They are in **inputs and arithmetic**: three `[CONFIRMED]`
technology pins are end-of-life as of today, one confirmed decision deletes a bounded context the
parent PAD defines, and the delivery plan consumes all 26 weeks of the six-month budget with zero
slack against a team size that appears once in passing and is absent from the assumption register.

Fixing the version pins is nearly free today and expensive after Wave 0 builds on them.

## Dimension verdicts

| Dimension | Verdict |
|---|---|
| Divergence points fixed | strong |
| Rules enforceable | strong |
| Deferred items safe | adequate |
| Named technology verified-current | **broken** |
| Coverage of parent PAD | thin |
| Operational envelope | strong |
| Delivery realism | thin |

## Findings by severity

### Critical (3)

**[Currency] Laravel 11 is end-of-life** (§1.1 D-03, §3.1)
Security support ended 2026-03-12. Laravel 13 is current. This is greenfield — switching costs
nothing now. The §3.1 rationale for Laravel over Nest.js is sound and survives the repin.
*Fix:* repin to Laravel 12/13; re-verify §5.2 tenancy middleware against it before Wave 0.

**[Currency] Nuxt 3 is end-of-life** (§1.1 D-13, §3.2)
Reached EOL 2026-07-31. Nuxt 4.5.x is current. Wave 0 builds the Nuxt shell and tenant theming —
exactly the work you do not want to do twice.
*Fix:* repin to Nuxt 4 before Wave 0.

**[Delivery] Zero-slack plan on an unstated team size** (§18, §19, §3.1)
Waves 0–3 = weeks 1–3 / 4–12 / 13–20 / 21–26 = exactly the six-month budget, no buffer, against
~62 tables in Waves 1–2, 19 modules, a Nuxt portal, an Android POS with durable ledger and OTA,
and five open decisions with blocking dates inside the plan. §3.1 mentions "a single backend
engineer and a technical lead" in passing; that assumption is **not in §19** while far smaller
ones (A-05, A-07) are.
*Fix:* add team composition as A-11; then extend, cut, or declare which wave is the real GO/NO-GO.

### High (4)

**[Coverage] D-10 deletes a PAD bounded context** (§1.1)
TAD puts tax "out of scope — handled on the payment side." PAD defines §2.22 Tax Services Context
and lists مالیات in §1.8. A child cannot unilaterally remove a parent's context.
*Fix:* amend the PAD, or restore tax as deferred-but-owned. Also name the owning system —
"the payment side" names no party.

**[Currency] targetSdk 32 is unpublishable on Google Play** (§1.1 D-14, §3.3)
From 2026-08-31 new apps/updates need API 36; existing apps need API 35+ to reach new users. The
decision also conflates `minSdk` (27 — correct, delivers the Android 8.0 baseline) with
`targetSdk` (no rationale given). If distribution is OTA-only the policy doesn't bind — but the
TAD never states the distribution channel.
*Fix:* state the channel. If Play is ever in scope, raise targetSdk. Keep minSdk 27.

**[Adversarial] No rule on whether cross-module references are real database FKs** (§4.1, §7.1)
§7.1 covers FK *naming*, not *enforcement*. Two teams can comply and produce inconsistent
referential guarantees — and a real FK across a module boundary blocks the later extraction that
§4 and the Dedicated-SaaS horizon depend on.
*Fix:* state that cross-module references are logical ids with no DB-level FK (or the inverse).

**[Adversarial] Tenant context has no defined propagation into queued jobs or the outbox relay**
(§5.2, §5.5, §10)
`SET LOCAL` is transaction-scoped and correct for requests. §5.5 covers Redis/Kafka/storage/logs
but never the DB connection of a background worker. A job opens a fresh connection with no
`app.tenant_id`. The dangerous compliant "fix" is a worker connecting via the §5.4 platform bypass
role — turning an audited escape hatch into a routine code path.
*Fix:* mandate `tenant_id` on every job and the relay, re-established through the same middleware;
forbid the bypass role to workers by configuration; add an in-job RLS isolation test to §16.4.

### Medium (4)

**[Coverage] D-12 and Cluster 14 add scope beyond the PAD's MVP** — bot notifications in MVP and
marketplace in Wave 3 are not in PAD §1.30, against a timeline with no slack.

**[Delivery] §18 overstates the critical path** — it claims O-01 and O-02 are "the only true
external blockers … both neutralised by the fake-adapter strategy," but A-04 (Payment Core
inquiry/verify endpoint) is bold-flagged in §19 and rated High in §20. A fake adapter cannot
neutralise a missing capability in a real upstream system.
*Fix:* promote A-04 to an O-item with an owner and a date earlier than week 6.

**[Adversarial] Read models permitted with no stated writer rule** (§4.1 rule 2, Cluster 15)
`customer_stats` happens to be resolved by the §10.5 `CustomerStatsProjector`, but it is defined in
Cluster 8, scheduled under Customer in Wave 2, and projected as a Reporting concern — three
placements, no rule. Cluster 15's other read models have no named projector.
*Fix:* every table has exactly one writing module, named; read models written only by projectors.

**[Currency] PHP 8.3 is security-only** until 2027-12-31 — a forced upgrade inside the first year
of a declared 3–5 year horizon. 8.4/8.5 cost nothing extra at greenfield.

### Low (2)

**[Currency] Redis 7 pinned** without the 2024 relicensing/Valkey split being visible.

**[Mechanical] Parent status is self-contradictory** — PAD header says تأیید نشده, PAD §1.1 says
تأییدشده. The TAD's "Not approved" matches one and not the other.

## What the document already gets right

Closed under adversarial attack: payment authority on `unknown` (§9.2 step 7 + §9.5's prohibition
on altering succeeded/failed without audited manual action) · receipt-before-sync surfaced to the
human via the pending annotation (§9.2) · double-claim prevented by `FOR UPDATE SKIP LOCKED`
(§9.3) · duplicate events via `processed_events` (§10.2) · Kafka removed from the correctness path
by the transactional outbox, which also neutralises A-02 (§10.2).

Also strong: `deptrac` as automated boundary enforcement rather than review convention (§4.1);
`FORCE ROW LEVEL SECURITY` plus a non-owner role, closing both silent-RLS-bypass paths (§5.2);
naming the RLS isolation test and the process-death test as the two that decide the project
(§16.4); a complete operational envelope (§3.4, §15, §16, §17) that domain-focused drafts skip.

## Reviewer files
- `reviews/review-currency.md`
- `reviews/review-adversarial.md`
- `reviews/review-rubric.md`
- `review-prd-blocked.md` (sibling PRD — validation blocked)

## Note on method
`scripts/lint_spine.py` was not run: it validates BMad `ARCHITECTURE-SPINE.md` format (AD IDs,
Binds/Prevents/Rule blocks), and this document is a conventional TAD, not a spine. Reviewer lenses
were run inline rather than as parallel subagents, per this session's operating constraints.
Version claims were verified against the live web on 2026-09-16, not recalled.
