# Reviewer: adversarial boundary lens

**Lens (configured `finalize_reviewers[1]`):** construct two units one level down that each obey
every stated rule to the letter yet still build incompatibly. Every such pair is a hole to close.

**Verdict: three live holes.** The boundary rules in §4.1 are unusually well-specified for a
modular monolith — they are stated as enforced (`deptrac` in CI), not aspirational, which is the
right instinct. The holes below are all cases where a rule covers the *code* dimension and leaves
the *data* or *runtime* dimension unstated.

## Findings

### [high] Cross-module references: no rule on whether they are real database FKs (§4.1, §7.1)
§4.1 rules 2–3 forbid a module reading or writing another module's tables; §4.2 gives each module
its own `Database/migrations`. §7.1's conventions table covers FK **naming** (`singular FK
(merchant_id)`) and says nothing about FK **enforcement** across module boundaries. The cluster
tables then list columns flatly as `organization_id | FK`, `business_category_id | FK`,
`bank_account_id | FK` without saying which are database constraints.

*Two compliant, incompatible units:* the Payment team writes `payments.merchant_id` as a real
`REFERENCES merchants(id) ON DELETE RESTRICT` in its own migration. The Customer team writes
`customers.merchant_id` as a bare `bigint` with a logical relationship only. Both obey every rule
in §4.1. The result is inconsistent referential guarantees across the schema and — materially —
§4's stated goal that "any module could later be extracted into a service without rewriting its
callers" holds for one module and is blocked by a database constraint for the other. The Dedicated-SaaS
horizon (§5.1) depends on that extractability.

*Fix:* add one rule to §7.1 — cross-module references are logical ids with no database-level FK
(integrity enforced in the owning module's application layer), while intra-module references are
real FKs. Or state the inverse. Either is defensible; silence is not.

### [high] Tenant context has no defined propagation into queued jobs or the outbox relay (§5.2, §5.5, §10)
§5.2's mechanism is `SET LOCAL app.tenant_id` inside the request transaction — correct, and the
reasoning about connection pooling is right. §5.5 then extends isolation to Redis, Kafka, object
storage and logs. **No line in the document covers the database connection of a background worker.**
§10.1 routes real work to Laravel queues (SMS, PDF generation, provider calls) and §10.2's relay is
a separate leader-elected process. A queued job opens a fresh connection with no `app.tenant_id`
set, so every RLS-protected read returns empty or errors on `current_setting`.

*Two compliant, incompatible units:* the Messaging team serialises `tenant_id` into the job payload
and re-establishes context at job start. The Notification team hits the same empty-result wall and
"solves" it by having its worker connect with the §5.4 platform-scope bypass role. Both teams are
obeying everything written down. The second silently converts the audited cross-tenant escape hatch
into a routine background code path — which is the exact failure §5.2 was designed to make impossible.

*Fix:* state that every queued job and the outbox relay carry `tenant_id` and re-establish context
via the same middleware, and that the §5.4 bypass role is unavailable to queue workers by
configuration. Add a CI test mirroring §16.4's RLS isolation test but executed inside a job.

### [medium] Read models are permitted as a cross-module read mechanism with no stated writer rule (§4.1 rule 2, Cluster 15)
§4.1 rule 2 allows cross-module reads "through the contract **or a read model**" but never says
which module may *write* one. The document resolves this by luck in one case — §10.5 names a
`CustomerStatsProjector` consuming `CustomerPurchaseRecorded` — but `customer_stats` is defined
inside Cluster 8 (Customer), listed under Customer work in Wave 2 (§18), and projected by what
reads as a Reporting concern. Three placements, no rule. Cluster 15's other read-model tables get
no named projector at all.

*Two compliant, incompatible units:* Reporting projects a table from events (eventually consistent).
The owning module writes the same table synchronously in its own transaction because the table sits
in its cluster. Two writers, two consistency models, numbers that differ by which path ran last —
and nothing in §4.1 broken.

*Fix:* state that every table has exactly one writing module, named in the cluster listing, and
that read models are written only by projectors consuming events — never synchronously by the
owning module.

## Attacks that FAILED (the document already closes these)
- **Payment authority on `unknown`.** §9.2 step 7 makes Payment Core authoritative; §9.5 forbids
  reconciliation from altering a `succeeded`/`failed` payment without an audited manual action.
  Pinned cleanly.
- **Receipt printed before sync.** §9.2 permits the print and mandates the
  "در انتظار همگام‌سازی" annotation plus a pending-sync indicator — the ambiguity is surfaced to
  the human rather than hidden.
- **Two devices claiming one payment request.** §9.3 uses `SELECT … FOR UPDATE SKIP LOCKED`.
- **Duplicate event processing.** §10.2 at-least-once + `processed_events` dedupe.
- **Kafka as a correctness dependency.** §10.2's transactional outbox removes it, and explicitly
  keeps the transport swappable — which also neutralises assumption A-02.
