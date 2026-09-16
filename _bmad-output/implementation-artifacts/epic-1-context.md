# Epic 1 Context: Platform Foundation & Tenant-Safe Skeleton

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Build the skeleton every later epic stands on: a request from a real tenant enters, resolves to exactly one tenant, authenticates through a swappable driver, is authorised by a composite access decision, writes under database-enforced row-level security, and emits a domain event through a transactional outbox — with automated proof in CI that no tenant can read another tenant's data, in both a web request and a background job. It also lays the two client foundations (portal shell, POS skeleton) and commissions the UX design contract later UI work depends on. Done when that vertical slice runs end to end and the isolation tests are green. Two gaps to be aware of: the product requirements document in the planning set is an unreadable extraction, so everything below comes from the architecture document and the epic breakdown; and no UX design contract exists yet — one story here produces it.

## Stories

- Story 1.1: Repository skeleton with enforced module boundaries
- Story 1.2: Tenant resolution on every request
- Story 1.3: Row-Level Security enforcing tenant isolation
- Story 1.4: Pluggable authentication driver
- Story 1.5: Membership, roles, permissions and scopes
- Story 1.6: Code-defined permission registry
- Story 1.7: Access decisions with Progressive Access statuses
- Story 1.8: Domain events through a transactional outbox
- Story 1.9: Tenant context inside background work
- Story 1.10: Cross-tenant isolation proven in CI
- Story 1.11: UX design contract
- Story 1.12: Portal shell with workspace routing and runtime branding
- Story 1.13: POS application skeleton and device identity

## Requirements & Constraints

- No code path may execute without a resolved tenant. A request matching none of the resolution paths is rejected; there is no default or fallback tenant, ever.
- Tenant isolation is a database guarantee, not an application convention — a forgotten filter must not be able to become a breach. Isolation is asserted automatically in both a request context and a queued job, and a failing assertion blocks the merge. A detected violation attempt is a security incident, not a bug.
- Authentication must be switchable by configuration between an external identity provider and internal token issuance, with no calling-code change and identical authorisation behaviour. Against the external provider, no password is stored here.
- Authorisation is decided server-side and re-evaluated on every request, regardless of what any client rendered.
- Permissions are code-defined; tenants compose roles only from permissions they are entitled to and can never introduce a new permission string.
- Business operations survive the event backbone being down: events accumulate and drain automatically rather than failing the write.
- Migrations are expand/contract only, reversible or explicitly flagged irreversible with a written rollback plan; destructive changes never ship alongside the code that stops using the column.
- Targets constraining this epic: portal interactive under ~2.5s p95, POS payment screen ready under ~1s from tap, 99.9% payment-path availability. Working sizing: thousands of merchants and devices, low tens of TPS at peak, load-tested at roughly 3x.

## Technical Decisions

- **Stack.** PHP 8.4 / Laravel 13 modular monolith on PHP-FPM; PostgreSQL 16; Redis for cache, locks and queues; Kafka as event backbone; Kubernetes. Portal: Nuxt 4 / Vue 3 / TypeScript with Pinia, Persian-first and RTL. POS: Kotlin, MVVM + Clean layering, single-activity, Hilt, Room with SQLCipher, Retrofit/OkHttp with certificate pinning, WorkManager, no third-party analytics SDK.
- **Module structure.** One deployable artifact, hard internal boundaries: nineteen modules, each layered into contracts, domain, application, infrastructure, HTTP, console, listeners, database, tests. A module exposes only interfaces and DTOs; ORM models are internal and never cross a boundary; modules depend on the shared platform module and other modules' contracts namespace only. Enforced by a static check in CI, not by review.
- **Tenancy.** Shared schema; a non-nullable tenant column immediately after the primary key on every tenant-scoped table, with row-level security both enabled and forced and a policy binding rows to the current session's tenant setting. The application connects as a role that is neither superuser nor table owner. Tenant context is set transaction-scoped so it cannot leak across pooled connections. Resolution order: custom domain, subdomain, tenant header, token claim — in middleware, before authentication.
- **Platform scope.** Cross-tenant admin visibility uses a separate database role with a bypass policy, activated only for platform-scope requests and audited every time. That role must be unavailable to queue workers and the relay — their connection strings grant the application role only.
- **Background work.** Every queued job carries its tenant in the payload, no exceptions; a job re-establishes tenant context through the same middleware as a request, inside its own transaction, before touching the database; a job that cannot resolve a tenant fails and is retried or dead-lettered rather than proceeding unscoped. The outbox relay is the only legitimate cross-tenant reader and must never dereference into tenant-scoped tables — payloads are self-contained at publish time.
- **Non-database isolation.** Tenant-prefixed cache keys, tenant-path-prefixed object storage, tenant in every event envelope and asserted by consumers, tenant and correlation ids as structured fields on logs and traces.
- **Authorisation.** One access-decision service composes identity, membership, role permissions, resource scope (platform/tenant/organization/merchant/store), tenant and merchant entitlements, merchant lifecycle level, acceptance status, device capability and data readiness into exactly one of seven statuses: Active, Limited, Preview, Preparation, Locked, Hidden, WaitingForData. Each decision carries a machine reason code, human message and next-step route. A framework gate wraps the service so there is a single authorisation call site. Locked returns 423 with reason and next step; a genuine permission failure returns 403.
- **Data conventions.** Internal integer keys never exposed; externally referenced entities carry a public UUID. Money is integer Rial. Timestamps are UTC — Jalali is presentation only. Snake_case plural tables, singular foreign keys. No database foreign key may cross a module boundary (cross-module references are logical ids, integrity enforced in the owning module); intra-module keys are real with explicit delete behaviour. Every table has exactly one writing module; read models are written only by a named projector consuming events.
- **Eventing.** Two mechanisms not to be conflated: queues for in-process async work, domain events for facts other modules react to. The application never writes to the backbone directly — it writes an outbox row in the same transaction as the state change, and a leader-elected relay polls pending rows in id order in bounded batches, publishes, and marks them published. At-least-once; consumers deduplicate through a processed-events table. Envelope: event id, type, version, occurrence time, tenant, aggregate type and id, correlation and causation ids, actor. Versioned topics, partitioned by tenant plus aggregate. No personal data in payloads — identifiers only.
- **API conventions.** URI-versioned surfaces, additive-only within a version, snake_case JSON, UUIDs externally, cursor pagination, ISO-8601 UTC. Correlation-id header on every request (generated if absent), idempotency key on unsafe operations, tenant-slug header for POS and partner surfaces. Errors carry a stable machine code plus localised message and correlation id; clients branch on codes only.
- **POS identity.** Device identity derived on first launch from stable hardware identifiers, with a keypair in the platform keystore, registered with the backend and unusable until bound. Two token layers — long-lived device token bound to the keystore key, short-lived per-cashier session token — both sent on every request, so cashier switching never re-registers the device. Crash and log events go to a local ring buffer uploaded in batches with the correlation id preserved. Distribution is over-the-air only, which is what makes the deliberately low target SDK valid.
- **Test gate (merge-blocking).** Unit tests on state machines, entitlement logic and money arithmetic; integration tests on every module contract; the isolation assertion in both request and job contexts; static boundary check plus a schema check that no foreign key crosses a module boundary.

## UX & Interaction Patterns

No UX design contract exists yet — producing one is a story in this epic, and later portal and POS work should reference it rather than invent patterns. Only these architectural constraints are fixed:

- One application presenting workspaces, not a panel per role. Workspace is routing and navigation driven by resolved capabilities; a single context endpoint returns memberships, roles, scopes, entitlements, workspace options and branding, and navigation is generated from it. Switching workspace or merchant must not require re-authentication.
- The interface reflects access decisions and never makes them. Each of the seven statuses has a defined rendering intent — normal; normal with a quota indicator; read-only with a plan note; a call to action to complete a prerequisite; disabled with reason and next step; not rendered; an empty state explaining what generates the data. No control may ever silently do nothing.
- Tenant branding is resolved by hostname and applied as CSS custom properties before mount, ETag-cached, with no partner-specific build.
- Persian-first: RTL layout, Jalali dates, Persian numerals in the interface; ISO-8601 UTC on the wire.
- Delegated access requires a persistent, non-dismissible impersonation banner wherever active.

## Cross-Story Dependencies

- The module skeleton and boundary enforcement come first — every other story lands inside that structure.
- Tenant resolution precedes row-level security; both, plus background tenant context, precede the isolation proof.
- The authentication driver precedes membership/roles/scopes, which precede the permission registry, which precedes the access-decision service.
- The outbox design and the background-work rules are interdependent — the relay's constraints are part of the outbox contract.
- The UX design contract gates portal screen work at volume here and in later epics; the portal shell and POS skeleton are what later device, sale and payment work builds on.
- This epic is the foundation for every subsequent epic; nothing downstream proceeds before its exit criterion is met.
