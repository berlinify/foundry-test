---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - "Paystar Market TAD.md (v0.2 — primary: architecture, MVP scope, delivery waves)"
  - "Paystar Market PAD.md (v1 — product architecture, MVP scope §1.30)"
excludedDocuments:
  - "Paystar Market PRD.md — EXCLUDED: corrupt PDF extraction, unreadable. Scope conflicts resolved in TAD §1.3"
missingDocuments:
  - "UX design contract (DESIGN.md / EXPERIENCE.md) — does not exist. UX-DRs below derived from TAD §13; see note"
scopeBasis: "TAD §1.3 (PAD wins on MVP scope) + D-17..D-21 + planning positions P-01..P-07"
---

# PayStar Market - Epic Breakdown

## Overview

Complete epic and story breakdown for the PayStar Market MVP, decomposed from the TAD v0.2
architecture and the PAD's MVP scope (§1.30).

**Prerequisite note.** The standard inputs for this workflow are a PRD and an Architecture
document. Here:

- **Architecture** — `Paystar Market TAD.md` v0.2. Present and complete.
- **PRD** — the supplied `Paystar Market PRD.md` is a damaged PDF extraction and cannot be read.
  Its requirements role is served by **PAD §1.30** (MVP scope) plus **TAD §1.3**, which records
  how each PRD/PAD/TAD scope conflict was resolved in favour of the narrowest shippable MVP.
- **UX design contract** — **does not exist.** No `DESIGN.md` / `EXPERIENCE.md` was found. The
  UX-DRs below are derived from TAD §13 (workspace routing, Progressive Access render states,
  branding) and are *architectural* UX requirements, not a design contract. **A `bmad-ux` pass is
  a real gap** — Epic 2 carries a story to close it before portal build starts in earnest.

**Out of MVP and not decomposed here** (TAD D-10, D-18–D-21): full cash register, product
catalog, inventory, cart, discount engine · RFM and behavioural segmentation · bot notifications
(Telegram/Bale) · marketplace · tax · QR payment · biolink · mobile management app · campaigns ·
loyalty · credit and lending · accounting connectors · analytical reporting · advanced cash
management and shifts.

## Requirements Inventory

### Functional Requirements

**Identity, tenancy and access**

FR1: Authenticate users through a pluggable driver — external provider (PayStar Auth OIDC / Keycloak) or internal Laravel Sanctum — switchable by configuration with no caller changes.
FR2: Resolve tenant per request in order: custom domain → subdomain → `X-Tenant-Slug` header → token claim. Reject unresolvable requests with `400 TENANT_NOT_RESOLVED`. There is no default tenant.
FR3: Manage tenant membership, roles, permissions and resource scopes (platform / tenant / organization / merchant / store).
FR4: Produce a composite access decision from identity, membership, role, scope, tenant entitlement, merchant entitlement, merchant lifecycle level, acceptance status, device capability and data readiness, returning one of `Active | Limited | Preview | Preparation | Locked | Hidden | WaitingForData` with a machine reason code, human message and next-step route.
FR5: Maintain a code-defined permission registry (`{module}.{resource}.{action}`) synced to the database on deploy. Partners may compose roles only from permissions their tenant is entitled to, and may never invent a permission.
FR6: Support time-boxed agent delegated access — scoped to one merchant, permissions limited to the intersection of the delegation grant and the target's role, financial/destructive permissions deny-listed, `act`/`sub` claims on the token, persistent impersonation banner, revocable by merchant, agent and platform ops, every request audited.
FR7: Manage tenants, branding, custom domains, offerings and entitlements.
FR8: Serve tenant branding at runtime by hostname (`GET /api/v1/tenant/branding`), applied as CSS custom properties with ETag caching. No partner-specific frontend build.
FR9: Produce a white-label Android build profile and staged OTA rollout for one launch partner (P-05).

**Merchant and acceptance**

FR10: Manage organization, merchant, store and merchant profile records.
FR11: Drive merchant lifecycle levels and Progressive Access from acceptance and data state.
FR12: Capture an acceptance application with its required KYC documents, bank account and identity data.
FR13: Enforce the acceptance state machine: `draft → ready_for_submission → submitted → under_review → {correction_required → resubmit | approved | rejected}` → `terminal_provisioning → activated ↔ suspended`.
FR14: Submit acceptance to the PSP through an adapter, map external status to a canonical value (never storing the raw external status as application status), and write every transition to history with its source (`internal` / `psp_webhook` / `psp_polling`).
FR15: Support agent lead capture, assignment, activity tracking and assisted merchant registration.

**Device and terminal**

FR16: Register a device, bind it to a merchant/store, and maintain a device session.
FR17: Provision terminals and record terminal capabilities.
FR18: Enforce device lifecycle `registered → bound → active → {blocked | retired}` including remote revoke.
FR19: Publish app releases, target them by build profile and tenant, and roll out OTA in stages by percentage → tenant → all, with a rollback release always prepared.

**Sale and invoice**

FR20: Enter a sale amount on POS via a retail calculator supporting Rial and Toman, display without three zeros, and personalisable operation buttons (e.g. VAT, fixed discount).
FR21: Create and finalise an invoice with tenant-scoped numbering and a personalisable header/template.
FR22: Print a personalised invoice on the POS printer through a `PrinterAdapter`.
FR23: Enforce the invoice state machine `draft → finalized → void` (void audited and only while unpaid). Post-finalisation amount changes create a new invoice or correction document, never an edit.
FR24: Lock an invoice (`payment_status = locked_unknown`) while any payment on it is `unknown`, permitting no new attempt, to prevent double charging.

**Payment**

FR25: Create a payment request carrying amount, merchant, store, device, terminal, idempotency key and correlation id.
FR26: On POS, write the terminal result to the encrypted local ledger **durably and before** any network call, then permit receipt printing.
FR27: Submit an attempt result to the backend keyed by the device `client_reference`, deduplicated server-side.
FR28: Enforce the payment state machine including `unknown`, entered when the terminal succeeded but the backend never confirmed, no result arrived within timeout, or Payment Core returned an indeterminate response.
FR29: Retry unsynced attempts from the device with exponential backoff (5s → 15s → 60s → 5m → 15m, capped), never deleting a `pending_sync` row until the server acknowledges a terminal state.
FR30: Block a device from starting new payments when pending-sync rows exceed a per-tenant threshold (default 20 items or 60 minutes).
FR31: Serve server-initiated payments to POS by long-polling (`GET /pos/payment-requests/next`, 25s hold), claiming with `SELECT … FOR UPDATE SKIP LOCKED` so two devices never claim one request, releasing on `expires_at`.
FR32: Provide a **manual reconciliation workflow** for `unknown` payments — the MVP resolution path per P-02.
FR33: Provide automatic `unknown` resolution via Payment Core inquiry, **behind a feature flag**, enabled if and when O-07 confirms the endpoint exists.
FR34: Run daily reconciliation — ingest PSP settlement for D-1, match on `rrn` + `terminal_no` + `amount`, classify as `matched` / `missing_local` / `missing_remote` / `amount_mismatch` / `status_mismatch`, open a support case for everything unresolved, and emit `ReconciliationMismatchDetected`.
FR35: Permit an audited manual override out of `unknown` under the `payment.unknown.override` permission, and never permit reconciliation to alter a `succeeded` or `failed` payment without one.

**Customer, messaging and reporting**

FR36: Register basic customer information at merchant scope.
FR37: Capture and trace customer consent, without which no marketing message may be sent.
FR38: Link a purchase to a customer.
FR39: Maintain the `customer_stats` read model exclusively through the `CustomerStatsProjector` consuming `CustomerPurchaseRecorded`.
FR40: Manage message templates and trigger rules.
FR41: Send a thank-you SMS on a qualifying payment, subject to consent.
FR42: Send manual and bulk messages with an approval step, recipient estimation, cost estimation, credit and quota enforcement.
FR43: Deliver SMS through a multi-provider adapter with automatic provider switching.
FR44: Provide operational dashboards and core reports for merchant, store, device and payment activity.
FR45: Export reports with field masking applied and every export audited.
FR46: Present an entitlement and service-catalog UI reflecting what the tenant and merchant are entitled to.

**Platform, integration and operations**

FR47: Maintain a provider registry with a uniform adapter contract, resilience policy (timeout, retry, circuit breaker), failure isolation and a dead-letter queue.
FR48: Expose a partner API surface and deliver outbound webhooks, deduplicated by provider event id.
FR49: Write an audit log for sensitive events and manage support cases and incidents.
FR50: Publish domain events through a transactional outbox relayed to Kafka, with consumers deduplicating through `processed_events`.

### NonFunctional Requirements

NFR1: Payment request creation p95 < 300 ms.
NFR2: Payment result submission p95 < 500 ms excluding Payment Core time.
NFR3: Portal page interactive p95 < 2.5 s.
NFR4: POS payment screen ready < 1 s from tap.
NFR5: Payment path availability 99.9%.
NFR6: Portal availability 99.5%.
NFR7: RPO 5 minutes (WAL streaming); RTO 1 hour.
NFR8: **Payment result durability 100%** — no acknowledged terminal result may be lost. The only NFR with no acceptable failure rate.
NFR9: Size for 5,000 merchants, 7,000 devices, 20 TPS sustained peak; load-test payment endpoints at 3× that before launch (P-06).
NFR10: Tenant isolation enforced by PostgreSQL Row-Level Security, not application `where` clauses, with `FORCE ROW LEVEL SECURITY` and a non-superuser non-owner application role.
NFR11: No cross-tenant leakage, proven by an automated CI test asserting tenant A cannot read tenant B — executed in **both** a request context and a queued-job context.
NFR12: KYC documents, bank accounts and national IDs held at the most restrictive data class — encrypted at rest, access audited, no export without masking (P-07).
NFR13: Every platform-scope request and every delegated-access request audited, on each request rather than at session start.
NFR14: Android 8.0 / 2 GB RAM baseline — minimal local state, no third-party analytics SDKs, no speculative caching.
NFR15: Observability via Prometheus, Grafana, Loki and OpenTelemetry, with correlation id propagated across POS, backend and provider calls.
NFR16: Expand/contract migrations only; zero-downtime sequence; every migration reversible or explicitly flagged irreversible with a written rollback plan.
NFR17: Retention policy applied per data class (§15.4).
NFR18: Persian-first, RTL by default, Jalali dates in the UI, **ISO-8601 UTC on the wire always**.
NFR19: Money stored as `bigint` in Rial — never float, never decimal.
NFR20: Idempotency enforced on payment request creation, attempt result submission, invoice finalisation, PSP submission, notification send and inbound webhooks.

### Additional Requirements

*(from TAD architecture — these shape stories directly)*

- **No starter template is specified.** This is greenfield; the repository and module skeleton is Epic 1, Story 1.
- Modular monolith, 19 modules, one deployable artifact. Module boundaries enforced by **`deptrac` in CI as a merge blocker** — not by review.
- A module exposes only `Contracts/` (interfaces + DTOs). It never queries or writes another module's tables. Eloquent models never cross a boundary.
- **No database foreign key crosses a module boundary** (§7.1). Cross-module references are logical ids; integrity is enforced in the owning module. Intra-module references keep real FKs.
- **Every table has exactly one writing module.** Read models are written only by named projectors consuming events — never synchronously by the module owning the source data.
- **Tenant context must propagate into queued jobs and the outbox relay** (§5.6): every job carries `tenant_id`, re-establishes context through the same middleware, and fails rather than proceeding unscoped. The platform-scope bypass role is unavailable to workers by connection string.
- Stack: PHP 8.4 / Laravel 13, PostgreSQL 16 (+ streaming replica, read replica for reporting only), Redis 7 or Valkey, Kafka, S3-compatible object storage (MinIO), Nuxt 4 / Vue 3 / Pinia, Kotlin + Hilt + Room + SQLCipher + WorkManager + Retrofit, Kubernetes.
- Build against `FakePspAdapter` and a mock `TerminalAdapter` throughout (P-03, P-04). No story blocks on the real Iran Kish spec or the terminal integration decision.
- OpenAPI 3.1 generated from route attributes; contract source-controlled.
- Scheduler is a Kubernetes CronJob invoking `artisan schedule:run` — single leader, not per-pod.
- Test gate (§16.4) enforced in CI as a merge blocker, including a static check that no FK crosses a module boundary.
- Secrets: provider credentials are never stored in the application database — only a reference.

### UX Design Requirements

*(derived from TAD §13 — **not** from a UX design contract, which does not exist)*

UX-DR1: One Nuxt application with workspace-based routing — `/platform`, `/partner`, `/merchant`, `/agent`, `/store`, `/support`, `/finance` — not separate applications per role.
UX-DR2: Navigation generated from `GET /api/v1/me/context` (memberships, roles, scopes, entitlements, workspace options, branding bundle). The user switches workspace or merchant **without re-authenticating**.
UX-DR3: Seven distinct render states for Progressive Access, one per `AccessDecision` status: `Active` (normal), `Limited` (quota indicator), `Preview` (read-only with plan note), `Preparation` (CTA to complete prerequisite), `Locked` (disabled with reason and next step), `Hidden` (not rendered), `WaitingForData` (empty state explaining what generates the data). This is the difference between "the button does nothing" and "here is what to do next."
UX-DR4: The UI reflects access decisions and never makes them — hiding a menu is presentation only; the backend re-evaluates every request.
UX-DR5: `Locked` renders from a `423 Locked` response carrying `reason_code`, `message` and `next_step_url` — distinct from a genuine `403`.
UX-DR6: Tenant branding resolved by hostname **before the app mounts**, applied as CSS custom properties, ETag-cached.
UX-DR7: Persian-first RTL layout with Jalali date display and Persian numerals, including RTL-capable charts (ECharts).
UX-DR8: A persistent, non-dismissible impersonation banner whenever a delegated-access session is active.
UX-DR9: POS UI designed for Android 8.0 on a 2 GB device with a small screen — payment screen ready under 1 s, pending-sync count always visible, receipts annotated "در انتظار همگام‌سازی" when unsynced.

### FR Coverage Map

FR1: Epic 1 — pluggable auth driver
FR2: Epic 1 — tenant resolution order, no default tenant
FR3: Epic 1 — membership, roles, permissions, scopes
FR4: Epic 1 — AccessDecisionService and its seven statuses (inputs enriched by Epics 2, 3, 6)
FR5: Epic 1 — code-defined permission registry
FR6: Epic 2 — agent delegated access
FR7: Epic 7 — tenants, branding, domains, offerings, entitlements
FR8: Epic 7 — runtime branding by hostname
FR9: Epic 7 — white-label build profile and staged OTA
FR10: Epic 2 — organization / merchant / store / profile
FR11: Epic 2 — merchant lifecycle and Progressive Access
FR12: Epic 2 — acceptance application and documents
FR13: Epic 2 — acceptance state machine
FR14: Epic 2 — PSP submission, canonical status mapping, transition history
FR15: Epic 2 — agent leads, assignment, assisted registration
FR16: Epic 3 — device registration, binding, session
FR17: Epic 3 — terminal provisioning and capabilities
FR18: Epic 3 — device lifecycle and remote revoke
FR19: Epic 3 — releases, targeting, staged OTA rollout
FR20: Epic 3 — POS retail calculator
FR21: Epic 3 — invoice create/finalise, numbering, template
FR22: Epic 3 — invoice printing
FR23: Epic 3 — invoice state machine
FR24: Epic 3 — invoice lock on unknown payment
FR25: Epic 3 — create payment request
FR26: Epic 3 — durable local write before network
FR27: Epic 3 — attempt result submission with idempotency
FR28: Epic 3 — payment state machine including unknown
FR29: Epic 3 — SyncWorker retry with backoff
FR30: Epic 3 — pending-sync device block threshold
FR31: Epic 3 — server-initiated payment by long-poll and claim
FR32: Epic 4 — manual reconciliation workflow (MVP path, P-02)
FR33: Epic 4 — automatic unknown resolver behind feature flag
FR34: Epic 4 — daily reconciliation, matching, classification
FR35: Epic 4 — audited manual override out of unknown
FR36: Epic 5 — basic customer registration
FR37: Epic 5 — consent capture and traceability
FR38: Epic 5 — purchase linking
FR39: Epic 5 — customer_stats via projector only
FR40: Epic 5 — message templates and rules
FR41: Epic 5 — thank-you SMS on payment
FR42: Epic 5 — manual and bulk send with approval, credit, quota
FR43: Epic 5 — multi-provider SMS with auto-switch
FR44: Epic 6 — dashboards and core reports
FR45: Epic 6 — export with masking and audit
FR46: Epic 6 — entitlement and service catalog UI
FR47: Epic 7 — provider registry, adapter contract, resilience, DLQ
FR48: Epic 7 — partner API and outbound webhooks
FR49: Epic 8 — audit log, support cases, incidents
FR50: Epic 1 — transactional outbox, relay, consumers

## Epic List

**8 epics, sequenced to the TAD §18 waves.** Epic 3 is the GO/NO-GO gate — everything before it
exists to make it possible, everything after it adds value on top of a product that already takes
money correctly.

### Epic 1: Platform Foundation & Tenant-Safe Skeleton
*Wave 0, weeks 1–3.* A request from a real tenant enters the system, authenticates, authorises,
writes to the database with Row-Level Security active, and emits a domain event — and an automated
test proves tenant A cannot read tenant B's data, in both a web request and a background job. No
user-facing feature ships here; what ships is the guarantee that everything built afterwards is
tenant-safe by construction rather than by remembering.
**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR50
**Why this is an epic and not a "technical layer":** it is a vertical slice with a single testable
exit criterion, not a horizontal one. It builds no table it does not traverse end to end.

### Epic 2: Merchant Onboarding to Activation
*Wave 1a.* A shop owner — or an agent sitting next to them — registers a business, completes the
profile and KYC documents, is submitted to the PSP, tracks the application through review and
correction, and arrives at `activated`. Progressive Access means the merchant always sees what is
unlocked, what is not, and exactly what to do next.
**FRs covered:** FR6, FR10, FR11, FR12, FR13, FR14, FR15

### Epic 3: Device Activation to First Payment — **GO/NO-GO**
*Wave 1b, the release-defining epic.* An activated merchant binds a POS device, enters an amount,
takes a card payment, and prints an invoice — **and the recorded result survives a forced network
outage and a process kill.** This epic is large by design: payment, invoice, device and the POS app
all touch the same critical path, and splitting them would create exactly the cross-epic feedback
loop the design principles warn against.
**FRs covered:** FR16, FR17, FR18, FR19, FR20, FR21, FR22, FR23, FR24, FR25, FR26, FR27, FR28, FR29, FR30, FR31

### Epic 4: Payment Integrity & Reconciliation
*Wave 1c.* Every rial that moves is accounted for. `unknown` payments get resolved — manually in
MVP, automatically the moment O-07 confirms the Payment Core inquiry endpoint exists — daily
settlement is matched and classified, mismatches open support cases, and no `succeeded` or `failed`
payment is ever altered without an audited human action.
**FRs covered:** FR32, FR33, FR34, FR35

### Epic 5: Customer Records & Merchant Messaging
*Wave 2.* A merchant builds a customer list from real purchases, records consent, and sends
thank-you and campaign messages within credit and quota — without which the transaction data stays
locked in reports nobody reads.
**FRs covered:** FR36, FR37, FR38, FR39, FR40, FR41, FR42, FR43

### Epic 6: Visibility — Dashboards, Reports & Entitlements
*Wave 2.* A merchant or partner sees what is happening in their business and what their plan
entitles them to, and can export it with sensitive fields masked and the export audited.
**FRs covered:** FR44, FR45, FR46

### Epic 7: Partner Platform — White Label, API & Webhooks
*Wave 2–3.* A software partner ships PayStar Market under their own brand, on their own domain,
and integrates it with their existing systems through a documented API and outbound webhooks —
with no fork, no partner branch, and no separate build.
**FRs covered:** FR7, FR8, FR9, FR47, FR48

### Epic 8: Operations & Launch Readiness
*Wave 3.* Support staff can see what happened and act on it; the system has been load-tested,
security-reviewed and runbooked; and a pilot merchant is onboarded end to end by the people who
will run it in production.
**FRs covered:** FR49

---

## Epic 1: Platform Foundation & Tenant-Safe Skeleton

*Wave 0, weeks 1–3.* A request from a real tenant enters, authenticates, authorises, writes under Row-Level Security, and emits a domain event — with automated proof that no tenant can read another's data, in both a web request and a background job.

**Exit criterion:** the vertical slice runs end to end and the isolation tests are green in CI.

### Story 1.1: Repository skeleton with enforced module boundaries

As a technical lead,
I want the module structure created with boundary enforcement wired into CI from day one,
So that the modular monolith cannot silently decay into a regular monolith.

**Acceptance Criteria:**

**Given** an empty repository
**When** the skeleton is created
**Then** `app/Modules/` contains the 19 module directories from TAD §4, each with `Contracts/`, `Domain/`, `Application/`, `Infrastructure/`, `Http/`, `Console/`, `Listeners/`, `Database/`, `Tests/`
**And** `deptrac` is configured with one layer per module and an explicit allow-list
**And** modules may depend only on `Platform` and on other modules' `Contracts` namespace

**Given** a merge request in which a module imports another module's Eloquent model
**When** CI runs
**Then** the `deptrac` check fails and the merge is blocked

**Given** a merge request adding a foreign key that crosses a module boundary
**When** CI runs
**Then** the schema check fails with the offending constraint named (TAD §7.1)

**Given** any migration
**When** it is reviewed in CI
**Then** it is expand/contract only — no destructive change in the same release as the code that stops using a column — and it is either reversible or explicitly flagged irreversible with a written rollback plan (NFR16)

**Given** a column being removed
**When** the change is sequenced
**Then** it follows add nullable → backfill in batches → switch code → make non-null → drop old, across separate releases

### Story 1.2: Tenant resolution on every request

As a platform operator,
I want every request resolved to exactly one tenant before anything else happens,
So that no code path can execute without a known tenant.

**Acceptance Criteria:**

**Given** a request arriving on a registered custom domain
**When** `ResolveTenantContext` middleware runs
**Then** the tenant is resolved from `tenant_domains.domain`

**Given** a request with no custom domain but a recognised subdomain
**When** middleware runs
**Then** the tenant is resolved from `tenants.slug`

**Given** a POS or partner-API request carrying `X-Tenant-Slug`
**When** middleware runs
**Then** the tenant is resolved from the header, and from the token `tenant_id` claim if the header is absent

**Given** a request matching none of the four resolution paths
**When** middleware runs
**Then** the response is `400 TENANT_NOT_RESOLVED`
**And** no default or fallback tenant is applied under any circumstance

### Story 1.3: Row-Level Security enforcing tenant isolation

As a platform operator,
I want tenant isolation guaranteed by the database rather than by application code,
So that a forgotten `where` clause cannot become a cross-tenant data breach.

**Acceptance Criteria:**

**Given** a tenant-scoped table
**When** migrations run
**Then** the table has `tenant_id bigint NOT NULL` immediately after the primary key
**And** both `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY` are applied
**And** a `tenant_isolation` policy restricts rows to `current_setting('app.tenant_id')`

**Given** the application connects to PostgreSQL
**When** the connection is established
**Then** it uses the `market_app` role, which is neither superuser nor table owner

**Given** tenant context has been set for tenant A
**When** a query selects from a tenant-scoped table containing rows for tenants A and B
**Then** only tenant A's rows are returned, with no application-level `where` clause present

**Given** a transaction completes and the pooled connection is reused
**When** the next request begins
**Then** `app.tenant_id` from the previous request is not visible, because it was set with `SET LOCAL`

### Story 1.4: Pluggable authentication driver

As a technical lead,
I want authentication behind a driver interface with both implementations working,
So that the unresolved question of PayStar Auth's OIDC support cannot block Wave 1.

**Acceptance Criteria:**

**Given** `AUTH_DRIVER=external`
**When** a user authenticates
**Then** the external provider's OIDC flow is used and tokens are validated against its JWKS
**And** no password is ever stored by PayStar Market

**Given** `AUTH_DRIVER=internal`
**When** a user authenticates
**Then** Laravel Sanctum issues the token

**Given** the driver is switched by configuration
**When** the application restarts
**Then** no calling code changes and all authorisation behaviour is unchanged

### Story 1.5: Membership, roles, permissions and scopes

As a platform operator,
I want users related to tenants through memberships carrying roles and resource scopes,
So that the same person can hold different access in different tenants.

**Acceptance Criteria:**

**Given** the Identity module
**When** migrations run
**Then** tables exist for memberships, roles, permissions, role-permission assignments and scopes — and no others

**Given** a user with membership in tenants A and B holding different roles
**When** they operate in tenant A
**Then** only tenant A's role and scope apply

**Given** a membership scoped to a single merchant
**When** the user requests a resource belonging to a different merchant in the same tenant
**Then** access is denied

### Story 1.6: Code-defined permission registry

As a technical lead,
I want permissions defined in code and synced on deploy,
So that partners can compose roles but can never invent a permission.

**Acceptance Criteria:**

**Given** the permission registry enum
**When** a deployment runs
**Then** the `permissions` table is synced to match it, in `{module}.{resource}.{action}` form

**Given** a partner composing a custom role
**When** they select permissions
**Then** only permissions their tenant is entitled to are offered
**And** any attempt to submit a permission string absent from the registry is rejected

### Story 1.7: Access decisions with Progressive Access statuses

As a merchant,
I want the system to tell me why something is unavailable and what unlocks it,
So that I am never faced with a control that silently does nothing.

**Acceptance Criteria:**

**Given** `AccessDecisionService`
**When** `decide(AccessQuery)` is called
**Then** it returns exactly one of `Active`, `Limited`, `Preview`, `Preparation`, `Locked`, `Hidden`, `WaitingForData`
**And** the decision carries a machine `reason_code`, a human message and a `next_step_url`

**Given** a capability the merchant's lifecycle level has not yet unlocked
**When** the merchant calls the endpoint
**Then** the response is `423 Locked` with reason and next step — **not** `403`

**Given** a user genuinely lacking the permission
**When** they call the endpoint
**Then** the response is `403`

**Given** any request to a protected endpoint
**When** it is handled
**Then** the backend re-evaluates the decision, regardless of what the UI rendered

### Story 1.8: Domain events through a transactional outbox

As a technical lead,
I want domain events written in the same transaction as the state change,
So that a Kafka incident is an inconvenience rather than an outage.

**Acceptance Criteria:**

**Given** a state change that publishes a domain event
**When** the transaction commits
**Then** the event row is in `outbox_events` in that same transaction
**And** the application has made no direct call to Kafka

**Given** pending outbox rows
**When** the relay runs
**Then** a single leader-elected worker polls `status = 'pending' ORDER BY id LIMIT 500`, publishes, and marks them published

**Given** Kafka is unavailable
**When** business operations continue
**Then** they succeed and events accumulate, draining automatically when Kafka returns

**Given** a consumer receives the same event twice
**When** it processes
**Then** `processed_events` makes the effect occur once

### Story 1.9: Tenant context inside background work

As a platform operator,
I want queued jobs and the outbox relay to carry tenant context explicitly,
So that background code cannot become the path that bypasses tenant isolation.

**Acceptance Criteria:**

**Given** any queued job
**When** it is dispatched
**Then** its payload carries `tenant_id`

**Given** a job begins executing
**When** it opens a database connection
**Then** it re-establishes tenant context through the same middleware as a web request, inside its own transaction

**Given** a job whose tenant cannot be resolved
**When** it runs
**Then** it fails and is retried or dead-lettered — it never proceeds unscoped

**Given** a queue worker pod
**When** it connects to PostgreSQL
**Then** its connection string provides only the `market_app` role; the platform-scope bypass role is unavailable to it

**Given** the outbox relay
**When** it publishes an event
**Then** it reads only `outbox_events` by id and never dereferences into tenant-scoped tables, because payloads are self-contained at publish time

### Story 1.10: Cross-tenant isolation proven in CI

As a technical lead,
I want isolation asserted automatically in both execution contexts,
So that the guarantee is verified continuously rather than assumed.

**Acceptance Criteria:**

**Given** seeded data for tenants A and B
**When** the isolation test runs inside a web request context
**Then** tenant A's context cannot read any tenant B row, across every tenant-scoped table

**Given** the same seeded data
**When** the identical assertion runs inside a queued job
**Then** it also passes — because passing in a request context proves nothing about a job

**Given** either test failing
**When** CI runs
**Then** the merge is blocked

### Story 1.11: UX design contract

As a product owner,
I want a real UX design contract before portal screens are built in volume,
So that the seven Progressive Access states and the POS screens are designed once rather than invented per screen.

**Acceptance Criteria:**

**Given** no `DESIGN.md` or `EXPERIENCE.md` exists today
**When** this story completes
**Then** a `bmad-ux` run has produced both, covering visual identity and tenant theming tokens, the seven `AccessDecision` render states, the impersonation banner, the POS payment and pending-sync screens, and RTL/Jalali conventions

**Given** the contract exists
**When** later portal and POS stories are implemented
**Then** they reference it rather than inventing patterns

> **Flagged call.** This story did not come from a requirement — it closes a gap found during
> breakdown. No UX design contract exists, and Epics 2, 3 and 6 all build UI on top of the same
> seven access states. Designing them once here is cheaper than reconciling six interpretations later.

### Story 1.12: Portal shell with workspace routing and runtime branding

As a user of any role,
I want one application that presents the workspace appropriate to me, in my tenant's branding,
So that I am not sent to a different panel for each responsibility.

**Acceptance Criteria:**

**Given** an authenticated user
**When** the portal loads
**Then** `GET /api/v1/me/context` returns memberships, roles, scopes, entitlements, workspace options and the branding bundle
**And** navigation is generated from that response rather than hard-coded

**Given** a user with access to more than one workspace or merchant
**When** they switch
**Then** the switch completes without re-authentication

**Given** a request arriving on a partner hostname
**When** the app boots
**Then** branding is resolved by hostname and applied as CSS custom properties **before mount**, ETag-cached, with no partner-specific build

**Given** the Persian locale
**When** any screen renders
**Then** layout is RTL, dates display as Jalali, numerals are Persian, and all values on the wire remain ISO-8601 UTC

### Story 1.13: POS application skeleton and device identity

As an Android engineer,
I want the POS app skeleton with secure device identity and observability,
So that Wave 1 device and payment stories build on a known-good base.

**Acceptance Criteria:**

**Given** a new install on an Android 8.0 / 2 GB device
**When** the app first launches
**Then** it generates and stores a device identity, targeting minSdk 27 / targetSdk 32 for OTA-only distribution (D-14, D-16)

**Given** the app is built
**When** dependencies are reviewed
**Then** it uses Kotlin, Hilt, Room with SQLCipher, Retrofit/OkHttp with certificate pinning and WorkManager — and contains no third-party analytics SDK

**Given** a crash or log event
**When** it occurs
**Then** it is written to a local ring buffer and uploaded in batches, with the correlation id preserved

---

## Epic 2: Merchant Onboarding to Activation

*Wave 1a.* A shop owner — or an agent beside them — registers a business, completes profile and KYC documents, is submitted to the PSP, tracks the application through review and correction, and reaches `activated`, always able to see what is unlocked and what to do next.

### Story 2.1: Register a business and its stores

As a shop owner,
I want to register my business and the stores I operate,
So that everything that follows is attached to a real business structure.

**Acceptance Criteria:**

**Given** an authenticated user with no merchant
**When** they register a business
**Then** an organization, a merchant and at least one store are created within the current tenant

**Given** a merchant
**When** additional stores are added
**Then** each belongs to exactly one merchant and inherits the tenant

**Given** the same business operating under two tenants
**When** records are created
**Then** they are independent merchant accounts with no shared rows (PAD §1.32)

### Story 2.2: Complete the merchant profile

As a shop owner,
I want to complete my business details,
So that I can be submitted for acceptance.

**Acceptance Criteria:**

**Given** a merchant in `draft`
**When** the owner saves partial details
**Then** progress is preserved and the merchant remains `draft`

**Given** all mandatory fields and documents are present and valid
**When** the profile is saved
**Then** the acceptance application becomes `ready_for_submission`

**Given** a mandatory field is missing
**When** submission is attempted
**Then** it is refused, naming each missing field

### Story 2.3: Submit KYC documents securely

As a shop owner,
I want to upload the documents required for acceptance,
So that my application can be reviewed.

**Acceptance Criteria:**

**Given** a required document type
**When** the owner uploads a file
**Then** it is stored in object storage under `tenants/{tenant_id}/`, encrypted at rest, and classified at the most restrictive data class (P-07)

**Given** any access to a stored KYC document
**When** it occurs
**Then** an audit row records who, when, and why

**Given** a document is exported or included in a report
**When** the export is produced
**Then** sensitive fields are masked and the export is audited

### Story 2.4: Acceptance application state machine

As a platform operator,
I want acceptance to move through defined states with full history,
So that an application's path is always reconstructible.

**Acceptance Criteria:**

**Given** an acceptance application
**When** it transitions
**Then** it follows `draft → ready_for_submission → submitted → under_review → {correction_required | approved | rejected}` and `approved → terminal_provisioning → activated ↔ suspended`

**Given** any transition
**When** it is written
**Then** `acceptance_status_history` records the from-state, to-state and source (`internal` / `psp_webhook` / `psp_polling`)

**Given** an application in `correction_required`
**When** the merchant corrects and resubmits
**Then** it returns to `submitted` with the correction recorded

**Given** an application in `rejected`
**When** any transition is attempted
**Then** it is refused — `rejected` is terminal

### Story 2.5: Submit to the PSP through an adapter

As a platform operator,
I want acceptance submitted through the provider adapter contract,
So that the real Iran Kish specification can arrive later without changing callers.

**Acceptance Criteria:**

**Given** an application in `ready_for_submission`
**When** it is submitted
**Then** it goes through the adapter contract, and in MVP through `FakePspAdapter` (P-03)

**Given** the PSP returns a status
**When** it is received
**Then** it is mapped to a canonical value, and the raw value is stored only in `psp_submissions.external_status`
**And** the external value is never stored as the application status

**Given** the same application is submitted twice
**When** the second submission occurs
**Then** idempotency on `application_id` + attempt number prevents a duplicate

**Given** the real Iran Kish adapter replaces the fake
**When** it is swapped in
**Then** no calling code changes

### Story 2.6: Ingest acceptance status changes

As a platform operator,
I want PSP status changes ingested by webhook and by polling,
So that an application progresses even when a webhook is missed.

**Acceptance Criteria:**

**Given** an inbound PSP webhook
**When** it arrives
**Then** it is deduplicated on the provider event id and applied as a transition with source `psp_webhook`

**Given** a submitted application with no webhook received within the polling interval
**When** the scheduled poll runs
**Then** status is fetched and applied with source `psp_polling`

**Given** the PSP applies rate limits
**When** polling runs
**Then** per-application backoff and a prioritised queue keep requests within limits

### Story 2.7: Merchant lifecycle drives Progressive Access

As a shop owner,
I want features to unlock as my application progresses,
So that I always know what is available now and what is next.

**Acceptance Criteria:**

**Given** a merchant at `draft`
**When** the portal renders payment features
**Then** each carries `Preparation` with a call-to-action to complete the profile

**Given** a merchant at `under_review`
**When** the portal renders payment features
**Then** each carries `Locked` with the reason and the next step

**Given** a merchant at `activated`
**When** entitled features are rendered
**Then** they carry `Active`

**Given** the merchant lifecycle level changes
**When** the change is applied
**Then** it results from an `AcceptanceApproved` event and never from Acceptance writing to `merchants` directly

### Story 2.8: Agent leads and assignment

As an agent,
I want to capture leads and have them assigned to me,
So that I can work a real pipeline of prospective merchants.

**Acceptance Criteria:**

**Given** an agent in the Agent workspace
**When** they create a lead
**Then** it is recorded with contact details, status and assignment

**Given** a lead is assigned
**When** the assigned agent views their workspace
**Then** only their assigned leads and activities are visible

**Given** an agent
**When** they attempt to view leads in another tenant
**Then** access is denied by RLS and scope

### Story 2.9: Agent-assisted merchant registration

As an agent,
I want to register a merchant on their behalf while sitting with them,
So that a shop owner unfamiliar with the system can still be onboarded.

**Acceptance Criteria:**

**Given** an agent working an assigned lead
**When** they complete registration on the merchant's behalf
**Then** the merchant, organization and store are created and linked to the originating lead

**Given** an agent-created merchant
**When** records are written
**Then** the agent is recorded as `created_by_user_id` and account ownership rests with the merchant, never the agent

### Story 2.10: Time-boxed delegated access

As a merchant,
I want any agent acting inside my account to be time-limited, visible and revocable,
So that assistance never becomes unsupervised control.

**Acceptance Criteria:**

**Given** a delegation grant
**When** a session starts
**Then** it is time-boxed to 30 minutes by default and 2 hours maximum, scoped to exactly one merchant, and recorded in `delegation_sessions`

**Given** an active delegated session
**When** permissions are evaluated
**Then** the effective set is the **intersection** of the agent's grant and the target's role — never a superset

**Given** a financial or destructive permission
**When** it is evaluated inside a delegated session
**Then** it is denied by deny-list regardless of the grant

**Given** an active delegated session
**When** any request is made
**Then** the token carries `act` and `sub` claims, and every request — not only session start — writes an audit row recording both

**Given** an active delegated session
**When** the UI renders
**Then** a persistent, non-dismissible impersonation banner is shown

**Given** an active delegated session
**When** the merchant, the agent, or platform ops revokes it
**Then** it terminates immediately and subsequent requests with that token are refused

### Story 2.11: Onboarding UI with Progressive Access states

As a shop owner,
I want the portal to show me exactly where my application stands and what to do next,
So that I am never stuck without knowing why.

**Acceptance Criteria:**

**Given** a feature returning each of the seven `AccessDecision` statuses
**When** the portal renders it
**Then** `Active` renders normally · `Limited` shows a quota indicator · `Preview` is read-only with a plan note · `Preparation` shows a CTA to the prerequisite · `Locked` is disabled with reason and next step · `Hidden` is not rendered · `WaitingForData` shows an empty state explaining what generates the data

**Given** a `423 Locked` response
**When** the UI handles it
**Then** it renders `reason_code`, `message` and a working `next_step_url`, distinctly from a `403`

**Given** the UI hides a menu item
**When** the underlying endpoint is called directly
**Then** the backend still refuses it — hiding is presentation only

---

## Epic 3: Device Activation to First Payment — GO/NO-GO

*Wave 1b, the release-defining epic.* An activated merchant binds a POS device, enters an amount, takes a card payment and prints an invoice — and the recorded result survives a forced network outage and a process kill.

**Exit criterion:** Story 3.18 passes. Until it does, the MVP has not shipped.

### Story 3.1: Register and bind a device

As a merchant,
I want to bind a POS device to my store,
So that payments taken on it are attributed to my business.

**Acceptance Criteria:**

**Given** an activated merchant and an unbound device
**When** binding is performed
**Then** the device is linked to exactly one merchant and store, and its state becomes `bound`

**Given** a device already bound to another merchant
**When** binding is attempted
**Then** it is refused

**Given** a merchant that is not `activated`
**When** binding is attempted
**Then** it is refused with `423 Locked` and a next step

### Story 3.2: Provision terminals and record capabilities

As a platform operator,
I want terminals provisioned against devices with their capabilities recorded,
So that the app only offers operations the hardware supports.

**Acceptance Criteria:**

**Given** an approved acceptance in `terminal_provisioning`
**When** the terminal is provisioned
**Then** the terminal record is created with its external PSP reference held as a consumed value, not mirrored as a schema

**Given** a terminal with recorded capabilities
**When** the POS app requests its capability set
**Then** only supported operations are offered in the UI

**Given** a terminal becomes active
**When** the event is emitted
**Then** the acceptance application transitions to `activated`

### Story 3.3: Device lifecycle and remote revoke

As a platform operator,
I want to block or retire a device remotely,
So that a lost or compromised terminal stops transacting immediately.

**Acceptance Criteria:**

**Given** a device in `active`
**When** an operator blocks it
**Then** its state becomes `blocked` and its next authenticated call is refused

**Given** a blocked device
**When** it attempts to start a payment
**Then** the attempt is refused and the reason is displayed on the device

**Given** a device with unsynced `pending_sync` rows
**When** it is blocked
**Then** it may still complete synchronisation of existing rows but may not start new payments

### Story 3.4: POS login and device session

As a cashier,
I want to log in on the POS device,
So that my actions are attributed to me rather than to the hardware.

**Acceptance Criteria:**

**Given** a bound, active device
**When** a cashier logs in
**Then** a device session is created carrying the user, merchant, store, device and terminal

**Given** an expired or revoked session
**When** any operation is attempted
**Then** it is refused and re-authentication is required

**Given** the payment screen is opened
**When** it renders
**Then** it is ready within 1 second from tap on the baseline device (NFR4)

### Story 3.5: Retail calculator

As a cashier,
I want a calculator built for retail amounts,
So that I enter the right amount quickly and without arithmetic mistakes.

**Acceptance Criteria:**

**Given** the calculator
**When** an amount is entered
**Then** Rial and Toman are both supported, with an option to display without three zeros

**Given** configured operation buttons
**When** the cashier taps one
**Then** the configured operation is applied — for example add 10% VAT, or apply a 5% discount

**Given** any amount
**When** it is stored or sent
**Then** it is a `bigint` in Rial, never a float or decimal (NFR19)

### Story 3.6: Create and finalise an invoice

As a cashier,
I want to produce a simple invoice for the sale,
So that the customer has a record and the amount is tied to the payment.

**Acceptance Criteria:**

**Given** an amount and basic invoice data
**When** the invoice is created
**Then** it is `draft` and carries tenant-scoped sequential numbering with no gaps or duplicates

**Given** a `draft` invoice
**When** it is finalised
**Then** its state becomes `finalized` and its amount becomes immutable

**Given** a request to finalise the same invoice twice
**When** the second request arrives with the same idempotency key
**Then** the stored response is returned and no second invoice exists

> **Scope note.** This is PAD §1.30's "Simple Sale and Invoice Experience" (D-17). No product
> catalog, cart, inventory or discount engine — those are D-18, out of MVP.

### Story 3.7: Personalise and print the invoice

As a shop owner,
I want my own header and logo on printed invoices,
So that the receipt represents my business.

**Acceptance Criteria:**

**Given** a configured invoice template with header and logo
**When** an invoice is printed
**Then** the personalisation is applied

**Given** a device family with a specific printer
**When** printing occurs
**Then** it goes through `PrinterAdapter`, with no printer-specific code outside the implementation

**Given** the printer is out of paper or unavailable
**When** printing is attempted
**Then** the failure is surfaced to the cashier and the invoice and payment records are unaffected

### Story 3.8: Invoice state machine and void

As a platform operator,
I want finalised invoices to be corrected rather than edited,
So that the financial record stays trustworthy.

**Acceptance Criteria:**

**Given** a `finalized` unpaid invoice
**When** an operator voids it with permission
**Then** it moves to `void` and the action is audited

**Given** a `finalized` invoice
**When** an amount change is requested
**Then** it is refused; a new invoice or a correction document is created instead

**Given** a failed payment on an invoice
**When** the cashier retries
**Then** the same invoice is reused with a new payment attempt

**Given** any invoice
**When** deletion is attempted
**Then** it is refused — financial records are never soft-deleted

### Story 3.9: Create a payment request

As a cashier,
I want to send the invoice amount to payment,
So that the amount charged always matches the amount on the invoice.

**Acceptance Criteria:**

**Given** a finalised invoice
**When** a payment request is created
**Then** it carries amount, merchant, store, device, terminal, idempotency key and correlation id, and is `created`

**Given** the same client-generated idempotency key
**When** the request is retried
**Then** the original `payment_request_id` is returned and no duplicate exists

**Given** a request under normal load
**When** it is created
**Then** p95 is under 300 ms (NFR1)

### Story 3.10: Invoke the terminal through an adapter

As an Android engineer,
I want terminal access behind an interface with a mock implementation,
So that payment work proceeds before the integration mechanism is decided.

**Acceptance Criteria:**

**Given** `TerminalAdapter`
**When** a payment is invoked
**Then** the call goes through the interface and returns rrn, stan and result code

**Given** MVP
**When** the app runs
**Then** a mock implementation satisfies the interface, and O-02's Intent/AIDL/SDK decision changes only the implementation (P-04)

**Given** a terminal timeout or indeterminate response
**When** it occurs
**Then** the attempt is recorded locally as requiring resolution rather than being discarded

### Story 3.11: Durable local ledger on the device

As a shop owner,
I want a terminal result recorded on the device before anything touches the network,
So that a result can never be lost to a network failure or a crash.

**Acceptance Criteria:**

**Given** the terminal returns a result
**When** the app handles it
**Then** the result is committed to the encrypted Room/SQLCipher ledger **before** any network call is attempted

**Given** a committed local row
**When** the app is killed or the device loses power immediately afterwards
**Then** the row is present and intact on restart

**Given** a local row in `pending_sync`
**When** any cleanup runs
**Then** it is not deleted until the server has acknowledged a terminal state

### Story 3.12: Submit the attempt result

As a cashier,
I want the payment result to reach the backend reliably,
So that my sales records match what actually happened.

**Acceptance Criteria:**

**Given** a locally committed result
**When** it is submitted
**Then** it carries `Idempotency-Key: <client_reference>` and the backend deduplicates on it

**Given** a duplicate submission of the same `client_reference`
**When** it arrives
**Then** the stored response is returned and no second payment is created

**Given** a submission in progress
**When** a concurrent duplicate arrives
**Then** the response is `409` with `Retry-After`

**Given** normal conditions
**When** the result is submitted
**Then** p95 is under 500 ms excluding Payment Core time (NFR2)

### Story 3.13: Payment state machine and invoice lock

As a shop owner,
I want the system to refuse a second charge whenever a payment's outcome is uncertain,
So that a customer is never charged twice.

**Acceptance Criteria:**

**Given** a payment
**When** it transitions
**Then** it follows `pending → {succeeded → reversed | failed | cancelled | unknown → {succeeded | failed}}`

**Given** the terminal succeeded but the backend never confirmed, **or** no result arrived within the timeout, **or** Payment Core returned an indeterminate response
**When** the condition is detected
**Then** the payment becomes `unknown`

**Given** a payment in `unknown`
**When** the invoice is inspected
**Then** `payment_status = locked_unknown` and no new payment attempt is permitted on that invoice

**Given** a payment in `unknown`
**When** exit is attempted
**Then** it is permitted only via Payment Core inquiry, daily reconciliation, or an audited override carrying `payment.unknown.override`

### Story 3.14: Retry unsynced results

As a shop owner,
I want the device to keep retrying until the server has the result,
So that a temporary outage does not cost me a record of a real sale.

**Acceptance Criteria:**

**Given** rows in `pending_sync`
**When** `SyncWorker` runs
**Then** it retries with backoff 5s → 15s → 60s → 5m → 15m, capped

**Given** a retry
**When** it is sent
**Then** it reuses the original idempotency key so the server deduplicates

**Given** the backend acknowledges a terminal state
**When** the acknowledgement is received
**Then** the local row is marked synced

**Given** unsynced rows exist
**When** the cashier views the app
**Then** a pending-sync indicator with a count is always visible, and any receipt printed for an unsynced result is annotated "در انتظار همگام‌سازی"

### Story 3.15: Block a device that has drifted too far

As a platform operator,
I want a device with too much unreconciled money to stop taking payments,
So that silent connectivity loss cannot accumulate unrecoverable transactions.

**Acceptance Criteria:**

**Given** a per-tenant threshold, default 20 items or 60 minutes
**When** pending-sync rows exceed it
**Then** the device is blocked from starting new payments

**Given** a blocked device
**When** the cashier attempts a payment
**Then** the reason and the required action are displayed

**Given** the backlog drains below the threshold
**When** synchronisation succeeds
**Then** the device resumes normally without operator intervention

### Story 3.16: Server-initiated payment

As a merchant using the portal,
I want to send a payment to a POS device from the portal or an API,
So that a sale started elsewhere can be completed at the terminal.

**Acceptance Criteria:**

**Given** a payment request created from the portal or partner API
**When** the POS polls `GET /api/v1/pos/payment-requests/next`
**Then** it receives `204` when nothing is pending and `200` with the request when one is, whereupon the request becomes `dispatched`

**Given** two devices polling simultaneously
**When** one request is available
**Then** `SELECT … FOR UPDATE SKIP LOCKED` ensures exactly one device claims it

**Given** an unclaimed request reaching `expires_at`
**When** expiry runs
**Then** it is released and becomes `expired`

**Given** the long-poll
**When** nothing is pending
**Then** the server holds for up to 25 seconds before responding

### Story 3.17: Release the app over the air

As a platform operator,
I want to roll a new POS build out in stages with rollback ready,
So that a bad release does not reach every terminal at once.

**Acceptance Criteria:**

**Given** a published release
**When** it is targeted
**Then** targeting is by build profile and tenant

**Given** a staged rollout
**When** it proceeds
**Then** it moves percentage → tenant → all, and a rollback release is always prepared

**Given** a device on an old version
**When** it checks for updates
**Then** it receives the release targeted to it, and a device that is offline or mid-shift is not forced to interrupt an in-progress payment

### Story 3.18: GO/NO-GO — survive outage and process death

As a technical lead,
I want the release-defining journey proven under failure,
So that we ship only if the durability guarantee actually holds.

**Acceptance Criteria:**

**Given** a merchant who registers, is accepted, binds a device
**When** they take a card payment and print an invoice
**Then** the full journey completes end to end against `FakePspAdapter` and the mock `TerminalAdapter`

**Given** a payment whose terminal call succeeded
**When** the network is forcibly severed before the result is submitted
**Then** the result is present in the local ledger, the receipt prints annotated as pending sync, and the result reaches the backend once connectivity returns

**Given** a payment whose terminal call succeeded
**When** the app process is killed immediately after the terminal returns
**Then** the result survives restart and synchronises

**Given** the instrumented Android tests for ledger durability, sync retry and process death mid-payment
**When** CI runs
**Then** they pass, and failure blocks the merge (§16.4)

**Given** all of the above
**When** the epic is reviewed
**Then** this is the GO/NO-GO gate: no acknowledged terminal result has been lost (NFR8)

---

## Epic 4: Payment Integrity & Reconciliation

*Wave 1c.* Every rial that moves is accounted for. `unknown` payments get resolved — manually in MVP, automatically once O-07 confirms the inquiry endpoint — settlement is matched daily, and no settled payment is altered without an audited human action.

### Story 4.1: Resolve unknown payments manually

As a support operator,
I want a worklist of unknown payments with the evidence needed to decide each one,
So that uncertain payments are resolved even without an automatic inquiry endpoint.

**Acceptance Criteria:**

**Given** payments in `unknown`
**When** the support operator opens the worklist
**Then** each row shows amount, merchant, store, device, terminal, rrn, stan, correlation id, timestamps and the locked invoice

**Given** an operator with `payment.unknown.override`
**When** they resolve an item to `succeeded` or `failed`
**Then** the payment transitions, the invoice unlocks, and an audit row records who, when, the evidence cited and the reason

**Given** an operator without that permission
**When** they attempt to resolve
**Then** the action is refused

**Given** an unknown payment older than its SLA
**When** the worklist is viewed
**Then** it is surfaced as ageing

> **Planning position P-02.** This is the **MVP resolution path**, built on the assumption that the
> Payment Core inquiry endpoint does not exist. If O-07 confirms it does, Story 4.4 switches on and
> this becomes the fallback it should always have been.

### Story 4.2: Ingest and match daily settlement

As a finance operator,
I want yesterday's settlement matched against our records automatically,
So that discrepancies surface the next morning rather than at month end.

**Acceptance Criteria:**

**Given** PSP settlement data for business date D-1 in machine-readable form
**When** the daily job runs
**Then** records are ingested and matched on `rrn` + `terminal_no` + `amount`

**Given** matching completes
**When** results are classified
**Then** each is `matched`, `missing_local`, `missing_remote`, `amount_mismatch` or `status_mismatch`

**Given** an `unknown` payment appearing as successful in settlement
**When** classification runs
**Then** it is auto-resolved to `succeeded` and the invoice unlocks

**Given** a `succeeded` or `failed` payment
**When** reconciliation processes it
**Then** it is never altered without an audited manual action

### Story 4.3: Open support cases from mismatches

As a support operator,
I want unresolved mismatches to become tracked cases,
So that nothing falls between reconciliation runs.

**Acceptance Criteria:**

**Given** a classification other than `matched` or an auto-resolved unknown
**When** reconciliation completes
**Then** a `support_case` is opened carrying the classification, the records compared and the correlation id

**Given** a mismatch is detected
**When** it is recorded
**Then** `ReconciliationMismatchDetected` is emitted through the outbox

**Given** an open case
**When** an operator works it
**Then** status, assignee and resolution notes are tracked, and closure is audited

### Story 4.4: Automatic unknown resolution behind a flag

As a technical lead,
I want automatic resolution ready but disabled until the endpoint is confirmed,
So that we neither block on O-07 nor discover its absence late.

**Acceptance Criteria:**

**Given** the feature flag is off
**When** a payment enters `unknown`
**Then** resolution follows the manual path in Story 4.1 and no inquiry call is made

**Given** the flag is on and Payment Core exposes inquiry/verify
**When** a payment enters `unknown`
**Then** the resolver queries Payment Core and transitions to the authoritative result

**Given** the flag is on and inquiry returns indeterminate or errors
**When** the resolver completes
**Then** the payment stays `unknown` and falls through to the manual worklist

**Given** either path resolves a payment
**When** the transition is written
**Then** Payment Core remains the authority and the source of the resolution is recorded

### Story 4.5: Audited override of a stuck payment

As a platform operator,
I want a last-resort override that is impossible to use invisibly,
So that stuck money can be freed without weakening the financial record.

**Acceptance Criteria:**

**Given** a payment in `unknown` that neither inquiry nor reconciliation resolved
**When** an operator holding `payment.unknown.override` overrides it
**Then** the transition is applied and audited with actor, timestamp, prior state, new state and stated reason

**Given** any override
**When** it is recorded
**Then** it appears in the audit log and in the reconciliation report for that date

**Given** a payment in `succeeded` or `failed`
**When** an override is attempted
**Then** it is refused

---

## Epic 5: Customer Records & Merchant Messaging

*Wave 2.* A merchant builds a customer list from real purchases, records consent, and sends thank-you and campaign messages within credit and quota.

### Story 5.1: Record basic customer information

As a cashier,
I want to record a customer against a sale,
So that my business has a customer list rather than a pile of anonymous receipts.

**Acceptance Criteria:**

**Given** a sale in progress
**When** the cashier records a customer
**Then** basic details are stored at merchant scope within the tenant

**Given** a customer already recorded by mobile number for that merchant
**When** the same number is entered again
**Then** the existing customer is matched rather than duplicated

**Given** customer data
**When** it is stored
**Then** it is isolated to the owning merchant and never visible cross-tenant or cross-merchant by default (PAD §1.33)

> **Scope note.** Basic customer information only (D-19). No RFM, behavioural labels or
> segmentation engine — out of MVP.

### Story 5.2: Capture and honour consent

As a customer of a shop,
I want my consent to marketing recorded and respected,
So that I only receive messages I agreed to.

**Acceptance Criteria:**

**Given** a customer record
**When** consent is captured
**Then** it is stored with its scope, timestamp, and the channel through which it was given, and is traceable

**Given** a customer who has not consented
**When** a marketing message send is attempted
**Then** the send is suppressed and the suppression is counted and visible

**Given** a customer who withdraws consent
**When** withdrawal is recorded
**Then** subsequent marketing sends to them are suppressed immediately

**Given** consent for one merchant
**When** another merchant or tenant attempts to rely on it
**Then** it does not apply — consent never crosses the scope it was given in

### Story 5.3: Link purchases to customers

As a shop owner,
I want purchases attached to customers,
So that my customer list reflects real buying history.

**Acceptance Criteria:**

**Given** a succeeded payment with a recorded customer
**When** the purchase is recorded
**Then** it is linked to that customer and `CustomerPurchaseRecorded` is emitted

**Given** a payment that is reversed
**When** the reversal is applied
**Then** the linked purchase is adjusted accordingly

### Story 5.4: Maintain customer statistics by projection

As a shop owner,
I want per-customer totals kept up to date,
So that I can see who my regulars are.

**Acceptance Criteria:**

**Given** `CustomerPurchaseRecorded`
**When** `CustomerStatsProjector` consumes it
**Then** `customer_stats` is updated with purchase count, total, average, first and last purchase, and last store

**Given** the `customer_stats` table
**When** any write occurs
**Then** it comes only from the projector — the Customer module never writes it synchronously (TAD §7.1)

**Given** the same event delivered twice
**When** the projector runs
**Then** `processed_events` makes the effect occur once

### Story 5.5: Message templates and trigger rules

As a shop owner,
I want reusable message templates and rules for when they fire,
So that routine messaging does not require me to write each message.

**Acceptance Criteria:**

**Given** a template with variables
**When** it is saved
**Then** it is validated and stored at tenant or merchant scope

**Given** a rule bound to an event type and conditions
**When** a qualifying event occurs
**Then** the rule is evaluated and a send is queued

**Given** a rule whose template references an undefined variable
**When** it is saved
**Then** it is refused, naming the variable

### Story 5.6: Multi-provider SMS delivery

As a platform operator,
I want SMS sent through multiple providers with automatic switching,
So that one provider's outage does not stop merchant messaging.

**Acceptance Criteria:**

**Given** more than one configured SMS provider
**When** the primary fails or times out
**Then** the adapter switches automatically to the next and the switch is recorded

**Given** any send
**When** it is dispatched
**Then** it is idempotent on `request_id` and its delivery status is tracked

**Given** provider credentials
**When** they are stored
**Then** only a reference is held in the application database, never the credential itself

**Given** a provider failing repeatedly
**When** the resilience policy triggers
**Then** the circuit opens, failures are isolated from other providers, and undeliverable messages land in the DLQ

### Story 5.7: Thank-you message after payment

As a shop owner,
I want customers thanked automatically after paying,
So that a routine transaction becomes a small piece of service.

**Acceptance Criteria:**

**Given** a succeeded payment linked to a consenting customer
**When** the rule fires
**Then** a thank-you SMS is queued with merchant branding applied

**Given** the customer has not consented
**When** the rule fires
**Then** no message is sent and the suppression is recorded

**Given** the merchant has no remaining credit or quota
**When** the rule fires
**Then** the send is refused and the merchant is notified

### Story 5.8: Manual and bulk sending with approval and quota

As a shop owner,
I want to send a message to a chosen audience with cost visible before I commit,
So that I never send a campaign larger or costlier than I intended.

**Acceptance Criteria:**

**Given** a selected audience
**When** the merchant prepares a bulk send
**Then** recipient count, suppressed count and estimated cost are shown before confirmation

**Given** a bulk job requiring approval
**When** it is submitted
**Then** it is held at `approval_status` pending and does not dispatch until approved

**Given** an approved bulk job
**When** it runs
**Then** progress is tracked as sent, failed and suppressed counts, and actual cost is recorded

**Given** a bulk job exceeding available credit
**When** it is submitted
**Then** it is refused before any message is sent

---

## Epic 6: Visibility — Dashboards, Reports & Entitlements

*Wave 2.* Merchants and partners can see what is happening and what their plan entitles them to, and can export it safely.

### Story 6.1: Reporting read models

As a technical lead,
I want reporting served from projections rather than operational tables,
So that reporting load never competes with taking payments.

**Acceptance Criteria:**

**Given** payment, sale and device events
**When** `ReportingProjector` consumes them
**Then** daily aggregates are maintained in the Reporting cluster's read models

**Given** a reporting query
**When** it executes
**Then** it runs against the read replica and never in a write path

**Given** each reporting read model
**When** writes occur
**Then** they come only from the named projector (TAD §7.1)

### Story 6.2: Operational dashboard

As a shop owner,
I want a dashboard of my business activity,
So that I can see how trading is going without running a report.

**Acceptance Criteria:**

**Given** an activated merchant with transaction history
**When** the dashboard loads
**Then** it shows payment volume and value, success rate, active devices and recent activity for the selected period

**Given** a merchant with no data yet
**When** the dashboard loads
**Then** it renders the `WaitingForData` state explaining what will generate the data

**Given** the dashboard
**When** it renders
**Then** it is interactive within 2.5 s p95 (NFR3), with RTL-capable charts and Persian numerals

### Story 6.3: Core operational reports

As a shop owner,
I want reports on sales, payments and devices,
So that I can reconcile my own records.

**Acceptance Criteria:**

**Given** a selected period and scope
**When** a report is run
**Then** results respect the user's resource scope and never include another merchant's data

**Given** a report at store scope
**When** a user scoped to one store runs it
**Then** only that store's rows appear

**Given** a large period
**When** the report runs
**Then** it is served from read models within the performance budget rather than by scanning operational tables

### Story 6.4: Export with masking and audit

As a compliance owner,
I want exports masked and recorded,
So that sensitive data does not leave the system unnoticed.

**Acceptance Criteria:**

**Given** a report containing sensitive fields
**When** it is exported
**Then** those fields are masked according to their data class (§15.1)

**Given** any export
**When** it is generated
**Then** an audit row records who, what, scope, period and when

**Given** a generated export file
**When** it is downloaded
**Then** scope is re-checked at download time, and the file is stored under `tenants/{tenant_id}/`

### Story 6.5: Entitlement and service catalog

As a merchant,
I want to see what my plan includes and what it does not,
So that unavailable features are understandable rather than mysterious.

**Acceptance Criteria:**

**Given** a merchant's tenant and merchant entitlements
**When** the service catalog renders
**Then** each capability shows its `AccessDecision` status with reason

**Given** a capability not included in the plan
**When** it renders
**Then** it shows `Preview` — read-only with a "not available on your plan" note — rather than being hidden

**Given** entitlements change
**When** `EntitlementRecalculator` consumes the lifecycle, acceptance or terminal event
**Then** access decisions are recomputed without a deployment

---

## Epic 7: Partner Platform — White Label, API & Webhooks

*Wave 2–3.* A software partner ships PayStar Market under their own brand and integrates it with their systems — with no fork, no partner branch and no separate build.

### Story 7.1: Manage tenants, offerings and entitlements

As a platform operator,
I want to create a partner tenant and define what it may offer,
So that a new partner can be onboarded without engineering work.

**Acceptance Criteria:**

**Given** platform-scope permission
**When** an operator creates a tenant
**Then** the tenant, its offering and its entitlements are created, and tenant creation remains an operations action rather than self-service (A-10)

**Given** a tenant's entitlements
**When** they are changed
**Then** access decisions for its merchants recompute without a deployment

**Given** any platform-scope request
**When** it is made
**Then** it uses the distinct bypass role, and every such request is audited (§5.4)

### Story 7.2: Partner branding and custom domains

As a partner,
I want the product to carry my brand on my own domain,
So that my customers experience it as my product.

**Acceptance Criteria:**

**Given** a partner tenant with branding configured
**When** a request arrives on its custom domain
**Then** `GET /api/v1/tenant/branding` resolves by hostname and the portal applies it as CSS custom properties before mount

**Given** a partner changes their logo or colours
**When** they save
**Then** the change is live without any frontend build, with ETag caching invalidated

**Given** a custom domain
**When** it is configured
**Then** TLS is issued via cert-manager and the domain is bound to exactly one tenant

### Story 7.3: White-label POS build profile

As a partner,
I want the POS app released under my brand,
So that my merchants see my identity on the terminal.

**Acceptance Criteria:**

**Given** a build profile defining name, icon, colours and endpoints
**When** the pipeline builds
**Then** it produces a branded APK from the single shared codebase, with no partner branch or fork (PAD §1.34)

**Given** one launch partner (P-05)
**When** the build runs
**Then** signing keys are held by PayStar operations and the process is documented and repeatable

**Given** a second partner added later
**When** their profile is created
**Then** it requires configuration only, not code

### Story 7.4: Staged OTA per partner

As a partner,
I want my merchants' devices updated on a controlled schedule,
So that a release does not disrupt my customers unexpectedly.

**Acceptance Criteria:**

**Given** a branded release
**When** it is rolled out
**Then** targeting is scoped to that partner's tenant and proceeds percentage → tenant → all

**Given** a problem discovered mid-rollout
**When** rollback is triggered
**Then** the prepared rollback release is distributed and the rollout halts

### Story 7.5: Provider registry and resilient adapters

As a platform operator,
I want every external provider reached through one contract with one resilience policy,
So that a failing provider degrades one capability instead of the product.

**Acceptance Criteria:**

**Given** a registered provider
**When** it is called
**Then** the call goes through the adapter contract with configured timeout, retry and circuit breaker

**Given** one provider failing continuously
**When** its circuit opens
**Then** other providers and capabilities are unaffected

**Given** a permanently failing message
**When** retries are exhausted
**Then** it lands in `dead_letters` with source, topic or endpoint, payload, error and retry count, and is replayable after resolution

**Given** any provider credential
**When** it is configured
**Then** the application database holds only a reference, with the secret in the platform secret store

### Story 7.6: Partner API surface

As a partner developer,
I want a documented, versioned API,
So that I can integrate without reading PayStar's source.

**Acceptance Criteria:**

**Given** the partner API
**When** it is called
**Then** requests resolve tenant by `X-Tenant-Slug` or token claim and are authorised by the same `AccessDecisionService` as the portal

**Given** the route definitions
**When** the build runs
**Then** an OpenAPI 3.1 document is generated from route attributes and committed to source control

**Given** an error
**When** it is returned
**Then** it follows the §14.3 error contract, distinguishing `403` from `423 Locked`

**Given** a partner exceeding rate limits
**When** they call
**Then** limits are applied per §14.4 with `Retry-After`

### Story 7.7: Outbound webhooks

As a partner developer,
I want events delivered to my endpoint reliably,
So that my systems stay in step without polling.

**Acceptance Criteria:**

**Given** a partner subscription
**When** a subscribed event occurs
**Then** `WebhookDispatcher` delivers it with a signed payload and the correlation id

**Given** a delivery failure
**When** retries run
**Then** they back off, and exhausted deliveries land in the DLQ

**Given** the same event delivered more than once
**When** the partner receives it
**Then** the event id allows them to deduplicate, and at-least-once delivery is documented as the contract

---

## Epic 8: Operations & Launch Readiness

*Wave 3.* Support staff can see what happened and act; the system is load-tested, security-reviewed and runbooked; and a pilot merchant is onboarded by the people who will run it.

### Story 8.1: Audit log and viewer

As a compliance owner,
I want sensitive actions recorded and searchable,
So that I can answer who did what, when.

**Acceptance Criteria:**

**Given** a sensitive event
**When** it occurs
**Then** `AuditProjector` ensures an audit row exists carrying actor, subject, action, scope, correlation id and timestamp

**Given** a delegated-access session
**When** any request is made
**Then** both `act` and `sub` are recorded on the audit row

**Given** an operator with audit permission
**When** they search
**Then** they can filter by actor, merchant, action type and period, within their own scope

### Story 8.2: Support cases and incidents

As a support operator,
I want one place to work merchant problems,
So that issues are tracked rather than living in messages.

**Acceptance Criteria:**

**Given** a reconciliation mismatch, a failed integration or a merchant report
**When** a case is opened
**Then** it carries source, linked records, correlation id, assignee and status

**Given** a case
**When** it is worked and closed
**Then** resolution notes are recorded and closure is audited

**Given** a support operator
**When** they view cases
**Then** they see only cases within their resource scope

### Story 8.3: Load test at 3× expected peak

As a technical lead,
I want payment endpoints proven under load before launch,
So that the NFRs are measured rather than asserted.

**Acceptance Criteria:**

**Given** the sizing basis of 5,000 merchants, 7,000 devices and 20 TPS sustained (P-06)
**When** the load test runs at 3× that peak
**Then** payment request creation holds p95 < 300 ms and result submission p95 < 500 ms excluding Payment Core

**Given** the test
**When** it completes
**Then** no acknowledged terminal result has been lost (NFR8)

**Given** results outside budget
**When** they are found
**Then** they are recorded with the bottleneck identified, before launch rather than after

### Story 8.4: Security review, runbooks and recovery drill

As a platform operator,
I want the recovery guarantees exercised rather than documented,
So that RPO and RTO are real numbers.

**Acceptance Criteria:**

**Given** the system before launch
**When** the security review runs
**Then** RLS enforcement, the platform-scope bypass path, delegated access, secret handling and data classification are each reviewed and findings closed or accepted with an owner

**Given** a simulated database loss
**When** recovery is performed from WAL streaming
**Then** RPO ≤ 5 minutes and RTO ≤ 1 hour are demonstrated, with the timings recorded

**Given** the operational surfaces
**When** runbooks are written
**Then** they cover reconciliation mismatch, device blocked, provider outage, DLQ drain, OTA rollback and tenant onboarding

### Story 8.5: Pilot merchant onboarding

As a product owner,
I want a real merchant onboarded end to end by the operations team,
So that we learn what breaks before it breaks at scale.

**Acceptance Criteria:**

**Given** the operations team, unaided by engineering
**When** they onboard a pilot merchant
**Then** the merchant registers, is accepted, binds a device, takes a live payment and prints an invoice

**Given** the pilot
**When** it runs for its agreed period
**Then** issues are logged as support cases and triaged before general availability

**Given** the pilot completes
**When** the launch decision is made
**Then** it is made against the exit criteria of Epics 3, 4 and this story — not against a date

### Story 8.6: Data retention and disposal

As a compliance owner,
I want each class of data retained only as long as its policy allows,
So that we are not holding KYC documents and personal data indefinitely by default.

**Acceptance Criteria:**

**Given** the data classification in §15.1
**When** the retention policy is applied
**Then** each class has a defined retention period and a disposal action, per §15.4 (NFR17)

**Given** data reaching the end of its retention period
**When** the scheduled disposal job runs
**Then** it is disposed of per policy, and the disposal is audited

**Given** a financial record
**When** disposal is evaluated
**Then** it is never soft-deleted, and any statutory retention requirement overrides the default period

**Given** the Data Controller and Data Processor roles
**When** the policy is published
**Then** both are named, having been determined with legal and compliance (PAD §1.33)

> **Flagged call.** O-05 (regulatory constraints) is still open. This story implements planning
> position P-07 — treat KYC documents, bank accounts and national IDs at the most restrictive
> class. If legal returns looser constraints we will have over-built a control, which is the
> acceptable direction to be wrong in.
