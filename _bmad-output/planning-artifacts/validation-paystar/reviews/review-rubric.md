# Rubric walker — good-spine checklist

Judged as an architecture spine at platform altitude, against the PAD it declares as parent.

## Overall verdict
This is a strong architecture document — the reasoning is load-bearing, the invariants are
enforced rather than aspirational, and the payment-durability design is genuinely careful. Its
weaknesses are not in thinking but in *inputs*: three confirmed technology pins are end-of-life,
one confirmed decision deletes a bounded context the parent PAD defines, and the delivery plan
consumes the entire six-month budget with zero slack against a team size that is never stated
and never listed as an assumption.

## Divergence points fixed for the level below — **strong**
§4.1's five module rules, §7.1's schema conventions, §9.4's idempotency key table, §10.1's
queue-vs-domain-event split, §14's API conventions, and §16.2's expand/contract migration rule
between them cover most of what two teams could otherwise choose incompatibly. The `deptrac`
enforcement call in §4.1 ("without this the modular monolith becomes a regular monolith within
two sprints — this has to be automated, not reviewed") is the correct instinct and correctly
automated. Three residual holes are in `review-adversarial.md`.

## Rules enforceable and actually preventing the stated divergence — **strong**
§5.2 is the standout: it identifies that application-level tenant scoping makes one forgotten
`where` clause a breach, moves the guarantee into the database, and then closes the two ways RLS
is silently defeated (`FORCE ROW LEVEL SECURITY` for the owner case, a non-superuser non-owner
role for the bypass case). `SET LOCAL` for pooling-safe transaction scoping is right. §16.4
naming the RLS isolation test and the process-death test as "the two that will save the project"
is the kind of prioritisation most documents never make.

## Deferred items safe — **adequate**
§18's deferral list is explicit and large (QR, campaigns, loyalty, RFM, credit, accounting,
biolink, mobile app, catalog/inventory, shifts). Deferring them cannot cause two units to
diverge because the schema is defined now and additions are additive (§7.3: ~103 tables defined,
~62 needed for Waves 1–2). That is a deliberate and defensible trade.

## Named technology verified-current — **broken**
Three of fifteen `[CONFIRMED]` decisions are EOL as of 2026-09-16. Full detail and sources in
`review-currency.md`. Severity is raised by their `[CONFIRMED]` status — the document's own
convention treats confirmed decisions as fixed, which makes them the hardest class to revisit.

## Coverage of the parent PAD's capabilities — **thin**

### Findings
- **[high] D-10 deletes a bounded context the PAD defines.** The TAD marks tax services "**out of
  scope** for PayStar Market — handled on the payment side." The parent PAD defines **§2.22 Tax
  Services Context** as one of its bounded contexts and lists مالیات in §1.8 as "internal service
  or external provider." A child document cannot unilaterally remove a parent's bounded context;
  under the skill's inheritance rule this is a conflict to surface, not a local override.
  *Fix:* either amend the PAD to drop the context, or restore tax as a deferred-but-owned context.
- **[medium] D-12 and Cluster 14 add scope the PAD's MVP does not contain.** PAD §1.30's MVP list
  does not include bot notifications or a marketplace; the TAD puts Telegram/Bale bots **in MVP**
  (D-12, Wave 2) and marketplace in Wave 3. Both may be right — but they are additions to the
  release-defining scope made in the child document, against a timeline with no slack.
- **[low] Affiliate correctly absent.** PAD §1.34 confirms Affiliate is an ecosystem-level shared
  capability, so its absence from the Market module list is consistent, not a gap.

## Every dimension the altitude owns decided/deferred/open — **strong**
The operational and environmental envelope that domain-focused drafts usually skip is present and
specific: §3.4 infrastructure, §15 security/observability/retention with a data classification
table, §16 environments, migration discipline, release strategy and a minimum test gate, §17 NFRs
with real numbers. No dimension is silent.

## Delivery realism — **thin**

### Findings
- **[critical] The delivery plan has zero slack and rests on an unstated team size.** Waves 0–3
  run weeks 1–3, 4–12, 13–20, 21–26 — exactly 26 weeks, exactly the six-month budget, with no
  buffer. Against that: ~62 tables across Waves 1–2, 19 modules, a Nuxt portal, an Android POS
  app with durable local ledger and OTA, and five open decisions (O-01…O-05) with blocking dates
  inside the plan. §3.1 mentions in passing "a single backend engineer and a technical lead" —
  and that resourcing assumption, on which the entire plan depends, **is not listed in §19's
  assumption register** while far less consequential items (A-05 settlement file format, A-07
  currency display) are.
  *Fix:* add team composition to §19 as A-11 and state the plan's headcount basis; then either
  extend the timeline, cut Wave 2/3 scope, or state explicitly which wave is the real GO/NO-GO
  and which are aspirational.
- **[medium] §18's critical-path claim is stronger than the risk register supports.** §18 states
  O-01 and O-02 "are the only true external blockers, and both are neutralised by the fake-adapter
  strategy." But A-04 (Payment Core exposes an inquiry/verify endpoint) is flagged in §19 in bold
  as the thing without which "`unknown` payments cannot be resolved automatically and
  reconciliation becomes fully manual," and the §20 register rates it High. A fake adapter does
  not neutralise a missing capability in a real upstream system.
  *Fix:* promote A-04 to an O-item with an owner and a required-by date earlier than week 6.

## Mechanical notes
- Document status is self-inconsistent with its parent: the TAD header says "Not approved", which
  matches the PAD's document-level "تأیید نشده" but contradicts PAD §1.1's chapter table
  ("تأییدشده"). The PAD's own two status fields disagree.
- `[CONFIRMED] / [ASSUMPTION] / [OPEN]` conventions are declared in §0 and used consistently;
  §19 and §1.2 are complete round-trips of the inline tags. This is better hygiene than most.
- D-10's phrase "handled on the payment side" names no owning system — the one place in the
  register where a decision defers to an unnamed party.
