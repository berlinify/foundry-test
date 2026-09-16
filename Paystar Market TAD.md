# PayStar Market — Technical Architecture Document (TAD)

| | |
|---|---|
| **Document** | Technical Architecture Document (TAD) |
| **Version** | 0.2 — Draft for review |
| **Status** | Not approved |
| **Owner** | Technical Lead |
| **Parent documents** | PayStar Market PAD v1 (Ch. 1–4), PayStar Market Introduction & PRD |
| **Scope** | MVP (6 months) with a 3–5 year architectural horizon |
| **Revision** | v0.2 — stack repinned off EOL versions; MVP scope conflicts with PAD/PRD resolved in favour of the narrowest shippable MVP; boundary rules closed for FKs, read models and tenant context in background work |
| **Answers the question** | *With what technology and infrastructure is the product architecture implemented?* |

---

## 0. How to read this document

The PAD answered *how the product is structured*. This document answers *how we build it*. Where the PAD stops (it explicitly excludes detailed database design, framework selection, and API contracts), this document starts.

Three conventions are used throughout:

- **[CONFIRMED]** — decided by the team and treated as fixed.
- **[ASSUMPTION]** — my working assumption in the absence of a decision. Every one of these is listed in §19 and must be confirmed or corrected.
- **[OPEN]** — a decision that is blocking or will become blocking, with an owner and a required-by date.

---

## 1. Decision register

### 1.1 Confirmed decisions

| # | Area | Decision |
|---|---|---|
| D-01 | Deployment | PayStar's own datacenter, Kubernetes |
| D-02 | Standards | Reuse existing PayStar project standards for CI/CD, observability, secrets |
| D-03 | Backend language | PHP 8.4 / Laravel 13 |
| D-04 | Backend topology | Modular monolith |
| D-05 | Database | PostgreSQL 16 |
| D-06 | Tenant isolation | Shared schema + `tenant_id` + Row-Level Security |
| D-07 | Authentication | Pluggable: external provider (PayStar Auth multi-tenant / Keycloak) **or** internal Laravel Sanctum, switchable by configuration |
| D-08 | Payment Core | Synchronous API, with an async-capable fallback design |
| D-09 | PSP | Iran Kish as the default; must remain replaceable |
| D-10 | Tax services | **Not built by PayStar Market in MVP.** The PAD §2.22 Tax Services Context is retained at platform level and deferred, not deleted; Market consumes it later through the Integration Hub like any other Provider. Owner of the capability: PayStar platform (payment side), to be named before the context is built |
| D-11 | SMS | Multi-provider with an existing auto-switching adapter |
| D-12 | Bot notifications | Telegram + Bale — ~~in MVP~~ **superseded by D-20: out of MVP** |
| D-13 | Web portal | Vue 3 / Nuxt 4, existing design system reused |
| D-14 | Android POS | minSdk 27, targetSdk 32, baseline device Android 8.0 Oreo. `minSdk 27` is what delivers the Android 8.0 baseline; `targetSdk 32` is deliberate and valid **only because distribution is OTA-only (D-16)** — it is below Google Play's current floor and could not be published there |
| D-15 | Event backbone | Apache Kafka |
| D-16 | POS distribution | **OTA only** (§12.6), onto PSP-supplied terminals. The app is not listed on Google Play in any horizon covered by this document. This is what makes D-14's `targetSdk` valid |
| D-17 | Sale scope in MVP | **Simple Sale and Invoice Experience** per PAD §1.30 — amount entry, basic invoice data, header personalisation, send-to-payment, print, record result. The full cash register is not built in MVP |
| D-18 | Product catalog, inventory, cart, discount engine | **Not in MVP.** Deferred whole. The PRD specifies these under its §6 صندوق فروشگاهی; PAD §1.30 removes them. PAD wins — see §1.3 |
| D-19 | Customer segmentation (RFM) | **Not in MVP.** MVP stores basic customer information only (PAD §1.30). RFM, labels-by-behaviour and the weighting/threshold engine described in the PRD are post-MVP |
| D-20 | Bot notifications (Telegram / Bale) | **Moved out of MVP** (reverses the earlier D-12). Not present in PAD §1.30's MVP list; removed to protect the Wave 1 date |
| D-21 | Marketplace | **Not in MVP.** Cluster 14 tables stay defined so the addition is additive, but no Marketplace delivery in the six-month window |

### 1.2 Open decisions

| # | Area | Question | Blocking from |
|---|---|---|---|
| O-01 | PSP | Iran Kish acceptance + terminal-provisioning API specification | Week 6 |
| O-02 | Terminal | Android terminal integration mechanism (Intent / AIDL / SDK) and whether the existing Sepehr/Oxin POS codebase is reused or rewritten | Week 4 |
| O-03 | White label | Number of partner builds at launch, signing key custody, silent-install capability | Week 12 |
| O-04 | Scale | Target merchant / device / TPS numbers for MVP sizing | Week 8 |
| O-05 | Compliance | Regulatory constraints on KYC documents, bank accounts, national IDs, retention | Week 4 |
| O-06 | QR payment | Provider selection | Deferred (not in Wave 1–2) |
| O-07 | Payment Core | Does Payment Core expose an inquiry/verify endpoint? Was A-04; promoted because a fake adapter cannot substitute for a missing capability in a real upstream system — without it, `unknown` resolution and reconciliation are manual forever | **Week 2** |

### 1.3 Scope conflicts between documents, and how they were resolved

Three documents describe this product and they do not agree on scope. Resolving that is a
prerequisite for a six-month MVP, so the rule is stated once here and applied throughout.

**Rule: where PAD, PRD and this document conflict on MVP scope, the narrowest shippable
definition wins — which in every current case is the PAD's.** The PRD specifies a materially
larger product than PAD §1.30 authorises. It is not wrong; it is a later horizon.

| Conflict | PRD position | PAD position | Resolution |
|---|---|---|---|
| Cash register | Full: product definition, sales, cart, invoice, discounts, **tax calculation**, sales reports (PRD §6) | Removed or deferred; replaced by "Simple Sale and Invoice Experience" (§1.30) | **PAD.** D-17 / D-18 |
| Tax | Calculated inside the register (PRD §6) | A bounded context of its own (§2.22); internal-or-provider (§1.8) | **Neither builds it in MVP.** Context retained at platform level, deferred. D-10 |
| RFM / customer segmentation | Significant feature with recency windows and weighting thresholds | Basic customer information only (§1.30) | **PAD.** D-19 |
| Segmentation axis | B2B / B2C throughout | Micro-merchant / software company / mid-size / enterprise (§1.12) | **PAD's axis is canonical** for architecture. B2B/B2C is a packaging and pricing concern, carried in Offering/Entitlement (Cluster 1), not an architectural dimension — it needs no structural change |
| Bot notifications | Present | Not in §1.30's MVP list | **Out of MVP.** D-20 |
| Marketplace | Present | Not in §1.30's MVP list | **Out of MVP.** D-21 |

Everything ruled out here stays **defined in the data model** (§7) so that adding it later is
additive rather than structural. Ruling it out of MVP is a delivery decision, not a design one.

**The PRD could not be read directly.** The supplied `Paystar Market PRD.md` is a damaged PDF
extraction (reversed token order, no recoverable line boundaries). Its positions above were
recovered from a partial reconstruction and are reliable as to *which features are specified*,
not as to any number or threshold. The PRD should be re-exported from source and this table
re-checked against it.

### 1.4 Planning positions taken to unblock delivery

The open items in §1.2 and §19 were blocking story breakdown. Rather than hold the plan, each is
given a **working position** below so work can be sequenced now. These are decisions of
convenience, not of conviction: each names what reverses it and what that reversal costs. None of
them should survive unconfirmed past the week given.

| # | Item | Working position | Reverses if | Cost of reversal |
|---|---|---|---|---|
| P-01 | A-11 team | 1 backend + 1 technical lead, 1 frontend, 1 Android, full-time for 26 weeks | Any role is part-time, shared, or unfilled at Wave 0 exit | Wave 2 is the release valve — cut customer/messaging before Wave 1 starts, not during |
| P-02 | O-07 Payment Core inquiry | **Assume it does not exist.** Build the manual reconciliation workflow (§9.5 steps 4–5) as the MVP path, and the automatic resolver behind a feature flag | Endpoint confirmed by week 2 | Low — the flag turns on the automatic path. This is the cheap direction to be wrong in; assuming it exists and finding out in week 18 is not |
| P-03 | O-01 Iran Kish spec | Build entirely against `FakePspAdapter` to the canonical contract (§11.4). No story blocks on the real spec | Spec arrives | Adapter implementation only — no caller changes, by design |
| P-04 | O-02 Terminal integration | Same: `TerminalAdapter` with a mock (§12.3). Treat the Sepehr/Oxin codebase as reference, not a base (A-06) | Decision to reuse the codebase | Re-plan the Android device stories only |
| P-05 | O-03 White-label partners | **One** partner build at launch. Signing keys held by PayStar operations | More than one partner contracted before week 12 | Build-profile work is already parameterised; second partner is configuration, not code |
| P-06 | O-04 MVP sizing | 5,000 merchants · 7,000 devices · 20 TPS sustained at peak · 3× that as the §16.4 load-test target | Real targets arrive and exceed these | Sizing and load-test thresholds only; no structural change at this order of magnitude |
| P-07 | O-05 Compliance | KYC documents, bank accounts and national IDs treated as the most restrictive class in §15.1: encrypted at rest, access audited, no export without masking | Legal returns stricter or looser constraints | Stricter is additive. Looser means we over-built a control, which is the acceptable direction |

**The one that matters is P-02.** Assuming the inquiry endpoint exists makes reconciliation an
afterthought; assuming it does not makes the manual workflow a first-class MVP deliverable that
gets built and staffed. If the endpoint turns out to exist, we switch on a flag and the manual
workflow becomes the fallback it should always have been.

---

## 2. System context

```
                          ┌────────────────────────────────────────┐
                          │            PayStar Ecosystem           │
                          │                                        │
   ┌──────────┐           │  ┌──────────────┐  ┌───────────────┐   │
   │  Web     │──HTTPS───►│  │ PayStar Auth │  │ Payment Core  │   │
   │ (Nuxt)   │           │  │  (external)  │  │  (external)   │   │
   └──────────┘           │  └──────▲───────┘  └───────▲───────┘   │
                          │         │                  │           │
   ┌──────────┐           │  ┌──────┴──────────────────┴───────┐   │
   │ Android  │──HTTPS───►│  │                                 │   │
   │   POS    │           │  │      PAYSTAR MARKET             │   │
   └──────────┘           │  │      (modular monolith)         │   │
                          │  │                                 │   │
   ┌──────────┐           │  │  ┌───────────────────────────┐  │   │
   │ Partner  │──HTTPS───►│  │  │      Integration Hub      │  │   │
   │   API    │           │  │  └─────────────┬─────────────┘  │   │
   └──────────┘           │  └────────────────┼────────────────┘   │
                          └───────────────────┼────────────────────┘
                                              │
              ┌───────────────┬───────────────┼───────────────┬──────────────┐
              ▼               ▼               ▼               ▼              ▼
        ┌──────────┐   ┌────────────┐  ┌────────────┐  ┌───────────┐  ┌───────────┐
        │ Iran Kish│   │ SMS        │  │ Telegram / │  │ QR        │  │ Accounting│
        │   PSP    │   │ Providers  │  │ Bale       │  │ Provider  │  │ (future)  │
        └──────────┘   └────────────┘  └────────────┘  └───────────┘  └───────────┘
```

**Trust boundaries.** Everything inside PayStar Market is trusted; everything reached through the Integration Hub is not. No external system writes directly to a Market database. No Market domain model mirrors an external provider's schema.

**Ownership boundaries** (from PAD §4.32, restated as engineering rules):

| Data | Owner | Market's role |
|---|---|---|
| User identity, OTP, tokens | PayStar Auth (or internal Sanctum) | Consumer |
| Valid financial result | Payment Core | Consumer + reconciler |
| External acceptance status | PSP | Consumer, mapped to canonical status |
| External terminal reference | PSP | Consumer |
| Everything else | **PayStar Market** | Owner |

---

## 3. Technology stack

### 3.1 Backend

| Concern | Choice | Note |
|---|---|---|
| Language / framework | PHP 8.4, Laravel 13 | D-03 |
| HTTP runtime | PHP-FPM behind Nginx | Octane/Swoole deliberately avoided in v1 — it changes the concurrency model and there is no DevOps capacity to debug it |
| Database | PostgreSQL 16 | Primary + streaming replica |
| Read replica usage | Reporting queries and exports only | Never in a write path |
| Cache / locks | Redis 7 (or Valkey) | Tenant-prefixed keys, distributed locks for payment critical sections. Redis relicensed in 2024 and Valkey forked from it; for self-hosted in-cluster use either is fine and the choice is reversible — flagged so it is a decision rather than an inheritance |
| Queue | Redis (Laravel queues) for in-process jobs; Kafka for domain events | Two different things — see §10 |
| Event backbone | Kafka | D-15 |
| Object storage | S3-compatible (MinIO in-cluster) | KYC documents, APKs, report exports |
| Search | PostgreSQL full-text | Elasticsearch is not justified at MVP scale |
| Scheduler | Kubernetes CronJob → `artisan schedule:run` | Single leader, not per-pod |
| API docs | OpenAPI 3.1, generated from route attributes | Contract is source-controlled |

**On Nest.js.** It was offered as an alternative. My recommendation is Laravel, for one reason that overrides taste: with a single backend engineer and a technical lead, the framework must minimise infrastructure code. Laravel gives multi-tenancy middleware, queues, scheduling, policy-based authorisation, migrations, and a mature RLS-compatible ORM out of the box. Nest.js would require assembling the same from libraries. If the team's actual depth is in TypeScript rather than PHP, reverse this — team fluency beats framework merit — but do not split: one backend language.

### 3.2 Frontend

| Concern | Choice |
|---|---|
| Framework | Nuxt 4 (Vue 3, Composition API, TypeScript) |
| Rendering | SPA mode with SSR only for the public Biolink pages (if Biolink is ever built) |
| Design system | Existing PayStar design system, extended with tenant theming tokens |
| State | Pinia |
| Data fetching | `useFetch` wrapper injecting tenant context, correlation ID, and auth token |
| i18n / RTL | Persian-first, RTL by default, `@nuxtjs/i18n` |
| Dates | Jalali throughout the UI; **ISO-8601 UTC on the wire, always** |
| Charts | ECharts (RTL-capable, Persian numerals) |
| Build | Vite, per-tenant runtime theming (not per-tenant builds) |

**Tenant theming must be runtime, not build-time.** A partner changing their logo must not trigger a frontend build. Branding is fetched from `GET /api/v1/tenant/branding` resolved by hostname, then applied as CSS custom properties.

### 3.3 Android POS

| Concern | Choice |
|---|---|
| Language | Kotlin |
| minSdk / targetSdk | 27 / 32 (D-14) — valid because distribution is OTA-only (D-16), not Google Play |
| Architecture | MVVM + Clean layering, single-activity |
| DI | Hilt |
| Local storage | Room (SQLite) + SQLCipher for the transaction ledger |
| Networking | Retrofit + OkHttp, certificate pinning |
| Background work | WorkManager (sync, health, OTA check) |
| Terminal access | `TerminalAdapter` interface, implementations per PSP — see §12.3 |
| Printing | `PrinterAdapter` interface, implementations per device family |
| Crash / logs | Local ring buffer + batched upload; no third-party SDK phoning home |

Android 8.0 and 2 GB RAM is the design constraint. It rules out heavy local databases, in-app analytics SDKs, and speculative caching. The local database exists for one purpose: **never lose a terminal result.**

### 3.4 Infrastructure

| Concern | Choice |
|---|---|
| Orchestration | Kubernetes (existing PayStar cluster) |
| Ingress | Nginx Ingress, wildcard TLS for `*.market.paystar.*`, per-partner custom domains via cert-manager |
| Config | ConfigMap + Secret, mounted as env |
| Secrets | Whatever PayStar standardises on (Vault preferred); provider credentials are **never** stored in the application database — only a reference |
| CI/CD | Reuse PayStar standard (assumed GitLab CI) |
| Observability | Prometheus + Grafana, Loki for logs, OpenTelemetry traces |
| Error tracking | Sentry (self-hosted) |

---

## 4. Modular monolith structure

One deployable artifact, hard internal boundaries. The goal is that any module could later be extracted into a service without rewriting its callers.

```
app/
  Modules/
    Platform/          # tenant context, RLS, correlation, base contracts
    Identity/          # membership, roles, permissions, scopes, delegation
    Tenancy/           # tenant, branding, domains, offerings, entitlements
    Merchant/          # organization, merchant, store, profiles
    Acceptance/        # applications, documents, PSP submission, provisioning
    Agent/             # leads, assignments, activities, tasks
    Device/            # devices, bindings, terminals, capabilities, sessions
    Release/           # app releases, build profiles, OTA targeting
    Sale/              # invoices, items, numbering, templates, printing
    Payment/           # requests, payments, attempts, recovery, reconciliation
    Customer/          # customers, consent, purchases, labels, stats
    Messaging/         # templates, rules, bulk jobs, credit
    Notification/      # delivery, providers, status
    Bot/               # telegram/bale linking and push
    Marketplace/       # catalog, visibility, leads
    Reporting/         # read models, aggregates, exports
    Integration/       # provider registry, adapters, webhooks, DLQ
    Audit/             # audit log, support cases, incidents
    Api/               # partner/provider API surface, webhooks out
```

### 4.1 Module boundary rules

These are enforced, not aspirational:

1. A module exposes a **public API** (`Modules/X/Contracts/`) — interfaces and DTOs only. Everything else is internal.
2. A module **never** queries another module's tables. Cross-module reads go through the contract or a read model.
3. A module **never** writes another module's tables. Cross-module writes go through the contract or a domain event.
4. Eloquent models are internal. They do not cross module boundaries — DTOs do.
5. Modules depend on `Platform` and on other modules' `Contracts` namespace only.

**Enforcement:** `deptrac` in CI with a layer per module and an explicit allow-list. A violating merge request fails. Without this the modular monolith becomes a regular monolith within two sprints — this has to be automated, not reviewed.

### 4.2 Layering inside a module

```
Modules/Payment/
  Contracts/          # public interfaces + DTOs (importable by others)
  Domain/             # entities, value objects, state machines, domain events
  Application/        # use cases / command handlers
  Infrastructure/     # Eloquent models, repositories, adapters
  Http/               # controllers, requests, resources
  Console/            # commands
  Listeners/          # event consumers
  Database/           # migrations, factories
  Tests/
```

---

## 5. Multi-tenancy and data isolation

### 5.1 The model

- **Shared database, shared schema, `tenant_id` on every tenant-scoped table.**
- **PostgreSQL Row-Level Security** as the enforcement mechanism, not application `where` clauses.
- Dedicated SaaS later = same code, separate deployment stamp with its own database. No code change.

The PRD requires that "no backend query on sensitive tables may run without the tenant filter." Achieving that with application-level scoping means one forgotten `where` clause is a cross-tenant data breach. RLS moves the guarantee into the database, where forgetting is not possible.

### 5.2 Implementation

Every tenant-scoped table:

```sql
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices FORCE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation ON invoices
  USING (tenant_id = current_setting('app.tenant_id')::bigint);
```

The application connects as a **non-superuser, non-table-owner** role (`market_app`) — otherwise RLS is silently bypassed. `FORCE ROW LEVEL SECURITY` covers the owner case.

Tenant context is set once per request, inside the transaction:

```php
DB::statement("SET LOCAL app.tenant_id = ?", [$tenantId]);
```

`SET LOCAL` is transaction-scoped, which is what we want with connection pooling — the setting cannot leak to the next request on a reused connection.

### 5.3 Tenant resolution order

1. Custom domain → `tenant_domains.domain`
2. Subdomain → `tenants.slug`
3. `X-Tenant-Slug` header (POS and partner API)
4. Token claim (`tenant_id` in the access token)

Resolution happens in `ResolveTenantContext` middleware, before authentication. A request with no resolvable tenant is rejected with `400 TENANT_NOT_RESOLVED` — there is no default tenant.

### 5.4 Platform-scope access

PayStar internal admins need cross-tenant visibility. This is handled by a distinct database role with a bypass policy, activated only for requests that carry a platform-scope permission, and **every such request is audited**. It is not the default connection.

### 5.5 Non-database isolation

| Surface | Rule |
|---|---|
| Redis | Every key prefixed `t:{tenant_id}:` |
| Kafka | `tenant_id` in the message envelope; consumers assert it |
| Object storage | Path prefix `tenants/{tenant_id}/` |
| Logs / traces | `tenant_id` as a structured field on every entry |
| Report exports | Generated under tenant context; download re-checks scope |

### 5.6 Tenant context in background work

`SET LOCAL` binds tenant context to a transaction, which is correct for an HTTP request and
wrong by omission for everything that does not have one. A queued job (§10.1) and the outbox
relay (§10.2) each open a fresh connection with no `app.tenant_id` set, so every RLS-protected
read returns empty.

These are rules, not suggestions, because the *convenient* fix for that empty result is a worker
connecting through the §5.4 platform-scope role — which would turn an audited cross-tenant escape
hatch into a routine background code path.

1. Every queued job carries `tenant_id` in its payload. No exceptions, including jobs that look
   tenant-agnostic today.
2. A job re-establishes context through the same middleware as a request, inside its own
   transaction, before touching the database.
3. A job that cannot resolve a tenant **fails**; it does not proceed unscoped.
4. The §5.4 platform-scope database role is **not available to queue workers or the relay.**
   Enforced by giving worker pods a connection string for `market_app` only.
5. The outbox relay is the one legitimate cross-tenant reader. It reads `outbox_events` by `id`
   and never dereferences into tenant-scoped tables — the event payload must be self-contained
   at publish time. A relay that needs to look something up is a design error in the publisher.

---

## 6. Identity, authentication and authorisation

### 6.1 The split

| Concern | Owner |
|---|---|
| Who the human is (OTP, password, tokens) | Auth provider — external or internal |
| What they may do here | **PayStar Market** |

This split is non-negotiable and comes straight from PAD §2.11. Market never stores a password when running against an external provider, and never delegates an authorisation decision.

### 6.2 Pluggable authentication (D-07)

```php
interface AuthDriver {
    public function verifyToken(string $token): ?AuthenticatedSubject;
    public function findSubjectByMobile(string $mobile): ?AuthenticatedSubject;
    public function startVerification(string $mobile): VerificationChallenge;
    public function completeVerification(string $id, string $code): AuthenticatedSubject;
    public function revokeSession(string $sessionRef): void;
    public function supportsRemoteRevocation(): bool;
}
```

Two implementations:

| Driver | Use |
|---|---|
| `OidcAuthDriver` | PayStar Auth or Keycloak. JWT verified against JWKS, cached, with `kid` rotation. Subject identified by the `sub` claim. |
| `SanctumAuthDriver` | Internal. Laravel Sanctum personal access tokens, OTP issued and verified by Market, password hashing via Argon2id. |

Selected by `AUTH_DRIVER=oidc|sanctum`. The rest of the application only ever sees `AuthenticatedSubject`.

**Design consequences:**

- `users.auth_provider` + `users.auth_subject` form a unique pair. Switching drivers is a migration, not a code change.
- Where the external provider cannot revoke remotely, Market maintains its own `user_sessions` table and enforces revocation on its own token introspection. `supportsRemoteRevocation()` tells the UI whether "log out all devices" is truly global.
- The POS uses a **separate token audience** with a shorter lifetime and device binding (see §12.2). A stolen web token must not open a cash register.

### 6.3 Authorisation model

Access is a composite decision, exactly as PAD §2.25 and §3.8 specify:

```
Identity
  + Tenant Membership
  + Role → Permissions
  + Resource Scope (platform | tenant | organization | merchant | store)
  + Tenant Entitlement
  + Merchant Entitlement
  + Merchant Lifecycle Level
  + Acceptance Status
  + Device Capability
  + Data Readiness
  = Access Decision
```

Implemented as a single service:

```php
interface AccessDecisionService {
    public function decide(AccessQuery $q): AccessDecision;
}

// AccessDecision::status ∈ {Active, Limited, Preview, Preparation,
//                           Locked, Hidden, WaitingForData}
// AccessDecision::reason  — machine code + human message + CTA route
```

Two rules that matter:

1. **The portal never decides.** Hiding a menu is presentation. The backend re-evaluates on every request. A Laravel `Gate` wraps `AccessDecisionService` so `$this->authorize()` is the single call site.
2. **`Locked` is not `403`.** Progressive Access requires telling the user *why* and *what unlocks it*. Locked responses return `423 Locked` with `reason_code`, `message`, and `next_step_url`. A genuine permission failure is `403`.

### 6.4 Permission registry

Permissions are **code-defined, not user-defined**. A PHP enum/registry is the source of truth, synced to the `permissions` table on deploy. Partners may compose Roles from permissions their tenant is entitled to — they may never invent a permission (PAD §3.7.2).

Naming: `{module}.{resource}.{action}` — `payment.transaction.refund`, `merchant.store.create`, `report.financial.export`.

### 6.5 Delegated access (Agent impersonation)

The highest-risk feature in the product. Requirements:

- Time-boxed (default 30 minutes, hard maximum 2 hours).
- Explicitly scoped to one merchant, with a permission subset that is the **intersection** of the agent's delegation grant and the target's role — never a superset.
- Financial and destructive permissions are excluded by a deny-list regardless of grant.
- The access token carries `act` (actor) and `sub` (subject) claims; every audit row records both.
- The UI displays a persistent, non-dismissible impersonation banner.
- Revocable by the merchant, by the agent, and by platform ops.
- Account ownership never transfers.

Stored in `delegation_sessions`, enforced in middleware, and logged on every request — not just at session start.

---

## 7. Data model (ERD)

### 7.1 Conventions

| Rule | Detail |
|---|---|
| Primary keys | `bigint` identity, internal. **Never exposed.** |
| Public identifiers | `uuid` column `public_id` on every externally-referenced entity |
| Tenant column | `tenant_id bigint NOT NULL` on every tenant-scoped table, first column after PK, RLS-enforced |
| Money | `bigint` **in Rial**, never float, never decimal. Currency column present for future-proofing but fixed to `IRR` in MVP |
| Timestamps | `timestamptz`, always UTC. Jalali conversion is a presentation concern |
| Soft deletes | Only where a business rule requires recall (customers, merchants). Financial records are **never** soft-deleted |
| Enums | Postgres native enums for stable domains, `varchar` + check constraint where values will grow |
| Audit columns | `created_at`, `updated_at`, `created_by_user_id`, `updated_by_user_id` on mutable business entities |
| Correlation | `correlation_id uuid` on every table that participates in a journey |
| Naming | `snake_case`, plural tables, singular FK (`merchant_id`) |
| **Cross-module references** | **Logical id only — no database-level foreign key across a module boundary.** Referential integrity for these is enforced in the owning module's application layer |
| **Intra-module references** | Real `FOREIGN KEY` constraints, with an explicit `ON DELETE` action |
| **Writer ownership** | **Every table has exactly one writing module**, named in its cluster listing below. No table is written by two modules |
| **Read models** | Written **only** by a named projector consuming domain events — never synchronously by the module that owns the source data |

**Why no cross-module FKs.** §4.1 forbids a module from reading or writing another module's
tables, but says nothing about the database enforcing a link between them. Left unstated, one
team writes `payments.merchant_id REFERENCES merchants(id) ON DELETE RESTRICT` and another
writes a bare `bigint` — both fully compliant with §4.1, and the schema ends up with
inconsistent guarantees. Worse, a real FK across a boundary is exactly what blocks the later
service extraction that §4 promises and the Dedicated-SaaS horizon (§5.1) depends on. The cost
of this rule is that orphan rows become possible; that cost is paid in the owning module's
application layer and in the `ON DELETE` behaviour it implements there.

**Why one writer per table.** §4.1 rule 2 permits a cross-module read "through the contract or a
read model" without saying who may write a read model. `customer_stats` happens to be safe — the
`CustomerStatsProjector` (§10.5) owns it — but it is *defined* under Cluster 8 and *scheduled*
under Customer work, so nothing prevents the Customer module from also maintaining it
synchronously. Two writers with two consistency models produce numbers that differ by whichever
path ran last, and no rule is broken. Hence: one writer, named.

### 7.2 Cluster map

```
 ┌──────────────┐
 │ 1. TENANCY   │──┐
 └──────────────┘  │
 ┌──────────────┐  │   ┌──────────────┐   ┌──────────────┐
 │ 2. IDENTITY  │──┼──►│ 3. MERCHANT  │──►│ 4. ACCEPTANCE│
 └──────────────┘  │   └──────┬───────┘   └──────┬───────┘
                   │          │                  │
 ┌──────────────┐  │          ▼                  ▼
 │ 13. AGENT    │──┘   ┌──────────────┐   ┌──────────────┐
 └──────────────┘      │ 6. SALE      │   │ 5. DEVICE    │
                       │  & INVOICE   │   │  & TERMINAL  │
                       └──────┬───────┘   └──────┬───────┘
                              │                  │
                              ▼                  ▼
                       ┌────────────────────────────────┐
                       │       7. PAYMENT               │
                       └──────┬─────────────────┬───────┘
                              │                 │
                              ▼                 ▼
                       ┌──────────────┐  ┌──────────────┐
                       │ 8. CUSTOMER  │  │ 15. REPORTING│
                       └──────┬───────┘  └──────────────┘
                              ▼
                       ┌──────────────┐  ┌──────────────┐
                       │ 9. MESSAGING │─►│ 10. BOT      │
                       └──────────────┘  └──────────────┘

 Cross-cutting: 11. INTEGRATION · 12. EVENTS & AUDIT · 14. MARKETPLACE
```

---

### Cluster 1 — Tenancy, Offering & White Label

**`tenants`**

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| public_id | uuid | unique |
| slug | varchar(64) | unique, used for subdomain + POS header |
| name | varchar(255) | |
| type | enum | `direct`, `white_label`, `enterprise` |
| status | enum | `provisioning`, `active`, `suspended`, `terminated` |
| deployment_profile | enum | `shared`, `dedicated` |
| default_locale | varchar(10) | `fa-IR` |
| timezone | varchar(64) | `Asia/Tehran` |
| contact_email / contact_phone | varchar | |
| activated_at, suspended_at | timestamptz | |

*Not RLS-scoped — this is the tenant registry itself. Access is platform-scope only.*

**`tenant_brandings`** — `tenant_id` (unique), `logo_file_id`, `favicon_file_id`, `app_display_name`, `primary_color`, `secondary_color`, `theme_tokens jsonb`, `support_phone`, `support_url`, `sms_sender_id`, `email_from`, `updated_by_user_id`

**`tenant_domains`** — `id`, `tenant_id`, `domain` (unique), `is_primary`, `verification_status` (`pending`/`verified`/`failed`), `verification_token`, `certificate_status`, `requested_by_user_id`, `approved_by_user_id`, `approved_at`

> Domain change requires PayStar approval (PAD §3.7.1) — hence the approval columns.

**`features`** — `id`, `code` (unique, e.g. `pos.operations`), `name`, `module`, `category`, `is_metered`, `default_status`

*Global registry, not tenant-scoped. Code-defined, synced on deploy.*

**`offerings`** — `id`, `code`, `name`, `description`, `status`
**`offering_features`** — `offering_id`, `feature_id`, `default_limits jsonb`

**`plans`** — `id`, `tenant_id` (nullable = platform plan), `offering_id`, `code`, `name`, `price_amount`, `currency`, `billing_cycle`, `status`
**`plan_features`** — `plan_id`, `feature_id`, `limits jsonb`

**`tenant_entitlements`** — `id`, `tenant_id`, `feature_id`, `status` (`active`/`limited`/`preview`/`locked`/`hidden`), `limits jsonb`, `valid_from`, `valid_to`, `source` (`contract`/`manual`), `granted_by_user_id`

Unique: `(tenant_id, feature_id)`

**`tenant_settings`** — `tenant_id`, `key`, `value jsonb`, unique `(tenant_id, key)`

**`pos_build_profiles`** — `id`, `tenant_id`, `code`, `package_id` (unique), `signing_key_ref`, `psp_adapter_code`, `theme_ref`, `app_name`, `status`, `created_by_user_id`

> One row per distinct Android artifact. This is the table that keeps White Label from becoming a fork.

---

### Cluster 2 — Identity & Access

**`users`** — global identity, **not tenant-scoped**

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| public_id | uuid | |
| auth_provider | varchar(32) | `oidc` / `sanctum` |
| auth_subject | varchar(255) | provider's subject id |
| mobile_e164 | varchar(20) | unique |
| first_name / last_name | varchar | |
| email | varchar | nullable |
| national_id_enc | bytea | encrypted, nullable |
| identity_verified_at | timestamptz | |
| status | enum | `active`, `blocked` |

Unique: `(auth_provider, auth_subject)`, `mobile_e164`

**`user_credentials`** — only populated under the Sanctum driver: `user_id`, `password_hash`, `password_changed_at`, `failed_attempts`, `locked_until`

**`memberships`** — the tenant-scoped presence of a user

`id`, `tenant_id`, `user_id`, `status` (`invited`/`active`/`suspended`), `terms_version`, `terms_accepted_at`, `invited_by_user_id`, `joined_at`, `last_active_at`

Unique: `(tenant_id, user_id)`

> This is the table that makes "one identity, many tenants, isolated data" work. A user existing in Auth does **not** imply access to a tenant.

**`roles`** — `id`, `tenant_id` (nullable = system role), `code`, `name`, `description`, `is_system`, `applicable_scope` (`platform`/`tenant`/`organization`/`merchant`/`store`), `created_by_user_id`

**`permissions`** — `id`, `code` (unique), `module`, `resource`, `action`, `description`, `is_sensitive`, `requires_audit`

**`role_permissions`** — `role_id`, `permission_id`

**`membership_roles`** — role assignment **with scope**

| Column | Notes |
|---|---|
| id, tenant_id, membership_id, role_id | |
| scope_type | `platform`/`tenant`/`organization`/`merchant`/`store` |
| scope_id | nullable bigint — the resource this role applies to |
| granted_by_user_id, granted_at, expires_at | |

Unique: `(membership_id, role_id, scope_type, scope_id)`

> One user can be a cashier in Store A and a manager in Store B under the same merchant. This table is why we do not need per-role panels.

**`delegation_sessions`** — `id`, `tenant_id`, `actor_user_id`, `target_membership_id`, `target_merchant_id`, `granted_permissions jsonb`, `reason`, `started_at`, `expires_at`, `revoked_at`, `revoked_by_user_id`, `ip_address`, `correlation_id`

**`user_sessions`** — `id`, `tenant_id`, `user_id`, `membership_id`, `channel` (`web`/`pos`/`mobile`/`api`), `device_id` (nullable), `token_ref` (jti), `ip_address`, `user_agent`, `issued_at`, `expires_at`, `last_seen_at`, `revoked_at`, `revoked_by_user_id`, `revoke_reason`

**`api_clients`** — `id`, `tenant_id`, `name`, `type` (`partner`/`provider`/`internal`), `client_id`, `secret_hash`, `scopes jsonb`, `allowed_ips inet[]`, `rate_limit_tier`, `status`, `rotated_at`

---

### Cluster 3 — Merchant Registry & Business Structure

**`business_categories`** — `id`, `code`, `name`, `parent_id`, `psp_category_map jsonb`, `status`

**`organizations`** — `id`, `tenant_id`, `public_id`, `name`, `legal_type` (`individual`/`legal`), `national_id_enc`, `company_registration_no`, `economic_code`, `status`, `created_by_user_id`

**`merchants`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id | |
| organization_id | FK |
| code | tenant-unique merchant code |
| display_name | |
| business_category_id | FK |
| lifecycle_level | smallint 1–4 (PAD §2.10) |
| status | `active`, `suspended`, `closed` |
| created_via | `self`, `agent`, `partner`, `import` |
| created_by_agent_id | nullable |
| activated_at, suspended_at, suspend_reason | |

Unique: `(tenant_id, code)`

> A business present in two tenants has **two `merchants` rows** and two `organizations` rows. They are never merged. This is PAD §1.32 made concrete.

**`merchant_profiles`** — `merchant_id` (unique), `legal_name`, `brand_name`, `phone`, `email`, `province`, `city`, `address`, `postal_code`, `latitude`, `longitude`, `logo_file_id`, `completed_at`

**`stores`** — `id`, `tenant_id`, `merchant_id`, `public_id`, `name`, `code`, `phone`, `province`, `city`, `address`, `postal_code`, `timezone`, `status`

**`merchant_settings`** — `merchant_id`, `key`, `value jsonb`

> Holds: invoice header config, print mode (combined/separate), thank-you SMS on/off, customer sharing across stores, amount limits.

**`merchant_entitlements`** — `id`, `tenant_id`, `merchant_id`, `feature_id`, `status`, `limits jsonb`, `valid_from`, `valid_to`, `source` (`plan`/`purchase`/`manual`), `plan_id`, `granted_by_user_id`

Unique: `(merchant_id, feature_id)`

**`merchant_lifecycle_history`** — `merchant_id`, `from_level`, `to_level`, `trigger_event`, `actor_user_id`, `occurred_at`

---

### Cluster 4 — Acceptance & Terminal Provisioning

**`acceptance_applications`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id, merchant_id | |
| status | `draft`, `ready_for_submission`, `submitted`, `under_review`, `correction_required`, `approved`, `rejected`, `terminal_provisioning`, `activated`, `suspended` |
| canonical_payload | jsonb — our internal model, **not** the PSP's |
| psp_code | `iran_kish` |
| psp_reference | external tracking id |
| bank_account_id | FK |
| store_id | target store |
| rejection_reason_code, rejection_note | |
| submitted_at, decided_at, activated_at | |
| created_by_user_id, created_by_agent_id | |
| correlation_id | uuid |

**`acceptance_documents`** — `id`, `tenant_id`, `application_id`, `document_type` (`national_card`, `business_license`, `bank_statement`, `shop_photo`, …), `file_id`, `status` (`uploaded`/`accepted`/`rejected`), `reviewer_note`, `uploaded_by_user_id`, `reviewed_by_user_id`

**`bank_accounts`** — `id`, `tenant_id`, `merchant_id`, `iban_enc` (encrypted Sheba), `iban_last4`, `bank_code`, `account_holder_name`, `status`, `verified_at`, `verification_method`

> Full IBAN encrypted at rest with a column key; `iban_last4` in clear for display and search. Never returned in full through any API except a single audited endpoint.

**`acceptance_status_history`** — `id`, `application_id`, `from_status`, `to_status`, `reason_code`, `note`, `actor_type`, `actor_user_id`, `source` (`internal`/`psp_webhook`/`psp_polling`), `occurred_at`, `correlation_id`

**`psp_submissions`** — `id`, `tenant_id`, `application_id`, `psp_code`, `operation`, `request_payload jsonb`, `response_payload jsonb`, `http_status`, `external_status`, `idempotency_key`, `submitted_at`, `duration_ms`

**`terminal_provisioning_requests`** — `id`, `tenant_id`, `merchant_id`, `store_id`, `application_id`, `type` (`new`/`activate`/`replace`/`transfer`), `status`, `psp_reference`, `requested_at`, `fulfilled_at`, `resulting_terminal_id`

---

### Cluster 5 — Device & Terminal Operations

**`devices`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id | |
| device_uid | hash of hardware identifiers, unique per tenant |
| serial_number, manufacturer, model | |
| os_version, api_level | |
| package_id | which build profile is installed |
| status | `registered`, `bound`, `blocked`, `retired` |
| first_seen_at, last_seen_at, last_ip | |

**`device_bindings`** — `id`, `tenant_id`, `device_id`, `merchant_id`, `store_id`, `bound_at`, `bound_by_user_id`, `activation_code_id`, `unbound_at`, `unbound_by_user_id`, `unbind_reason`

> Historical table. The active binding is the row with `unbound_at IS NULL`. Partial unique index enforces one active binding per device.

**`device_activation_codes`** — `id`, `tenant_id`, `merchant_id`, `store_id`, `code` (short, human-typeable), `expires_at`, `used_at`, `used_by_device_id`, `created_by_user_id`

**`device_capabilities`** — `id`, `device_id`, `capability_code` (`printer`, `internal_terminal`, `nfc`, `scanner`, `customer_display`), `is_available`, `details jsonb`, `detected_at`

**`device_sessions`** — `id`, `tenant_id`, `device_id`, `user_id`, `membership_id`, `token_ref`, `started_at`, `last_heartbeat_at`, `expires_at`, `revoked_at`, `revoked_by_user_id`

**`terminals`**

| Column | Notes |
|---|---|
| id, tenant_id, merchant_id, store_id | |
| psp_code | |
| terminal_no | PSP terminal number |
| acceptor_id | PSP merchant/acceptor number |
| type | `internal` (on-device) / `external` |
| status | `provisioning`, `active`, `suspended`, `revoked` |
| activated_at, revoked_at | |

Unique: `(psp_code, terminal_no)`

**`device_terminals`** — `device_id`, `terminal_id`, `is_default`, `linked_at`, `unlinked_at`

**`app_releases`** — `id`, `tenant_id` (nullable = platform), `build_profile_id`, `version_code`, `version_name`, `apk_file_id`, `sha256`, `size_bytes`, `min_supported_version_code`, `force_update`, `release_notes`, `status` (`draft`/`staged`/`published`/`rolled_back`), `published_by_user_id`, `published_at`

Unique: `(build_profile_id, version_code)`

**`app_release_targets`** — `id`, `release_id`, `target_type` (`all`/`tenant`/`merchant`/`store`/`device`/`percentage`), `target_id`, `percentage`

**`device_installed_versions`** — `device_id`, `version_code`, `version_name`, `installed_at`, `update_status`, `update_error`

**`device_health_reports`** — `id`, `tenant_id`, `device_id`, `battery_level`, `storage_free_mb`, `network_type`, `pending_sync_count`, `app_version_code`, `reported_at`

> High-volume, low-value-per-row. Partitioned monthly, retained 90 days.

---

### Cluster 6 — Sale & Invoice

**`invoices`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id | |
| merchant_id, store_id, device_id, created_by_user_id | full context |
| invoice_number | tenant/merchant-unique, assigned at finalisation |
| number_series | |
| status | `draft`, `finalized`, `void` |
| payment_status | `unpaid`, `pending`, `paid`, `failed`, `locked_unknown` |
| subtotal_amount | bigint, Rial |
| discount_type | `none`/`percent`/`fixed` |
| discount_value, discount_amount | |
| total_amount | |
| currency | `IRR` |
| customer_id | nullable |
| finalized_at, voided_at, void_reason | |
| idempotency_key, correlation_id | |

Unique: `(merchant_id, number_series, invoice_number)` where `invoice_number IS NOT NULL`

> **Non-tax document.** Per D-10 there is no tax invoice in Market at all.
>
> **Numbering** is assigned only on finalisation, from `invoice_number_sequences` using `SELECT ... FOR UPDATE` inside the finalise transaction. Gapless numbering and high concurrency are in tension; merchant-scoped sequences make the contention negligible.

**`invoice_items`** — `id`, `tenant_id`, `invoice_id`, `line_no`, `title`, `quantity`, `unit_price`, `discount_amount`, `line_total`

> Free-text lines. No `product_id` — there is no Product Catalog in MVP, and the invoice must not depend on one (PAD §2.14). The column can be added later without restructuring.

**`invoice_number_sequences`** — `id`, `tenant_id`, `merchant_id`, `series`, `period_key`, `last_number`, unique `(merchant_id, series, period_key)`

**`invoice_templates`** — `id`, `tenant_id`, `merchant_id`, `header_logo_file_id`, `business_name`, `address_line`, `phone`, `footer_text`, `show_customer`, `show_items`, `print_mode` (`combined`/`separate`), `paper_width_mm`, `updated_by_user_id`

**`invoice_prints`** — `id`, `tenant_id`, `invoice_id`, `device_id`, `printed_by_user_id`, `is_reprint`, `copy_number`, `reason`, `printed_at`

> Reprints must be marked and audited (PAD §4.14).

---

### Cluster 7 — Payment & Transaction Orchestration

This cluster carries the most correctness risk in the product.

**`payment_requests`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id | |
| merchant_id, store_id, device_id, terminal_id | device/terminal nullable at creation |
| invoice_id | nullable |
| type | `purchase`, `bill`, `balance_inquiry`, `qr` |
| origin_channel | `pos`, `web`, `partner_api` |
| amount, currency | |
| status | `created`, `dispatched`, `in_progress`, `completed`, `expired`, `cancelled` |
| idempotency_key | **unique per tenant** |
| correlation_id | |
| requested_by_user_id, requested_by_api_client_id | |
| expires_at, created_at | |

> Created **before** the terminal opens (PAD §4.11). This ordering is what makes recovery possible: if everything after this point is lost, we still know a payment was attempted, for what amount, by whom.

**`payments`** — the logical payment

`id`, `tenant_id`, `public_id`, `payment_request_id`, `merchant_id`, `store_id`, `invoice_id`, `status` (`pending`, `succeeded`, `failed`, `cancelled`, `unknown`, `reversed`), `amount`, `settled_amount`, `currency`, `method` (`card`/`qr`/`bill`), `succeeded_at`, `finalized_at`

**`payment_attempts`** — each independent try

| Column | Notes |
|---|---|
| id, tenant_id, payment_id, attempt_no | |
| device_id, terminal_id, performed_by_user_id | |
| status | `started`, `terminal_success`, `terminal_failed`, `pending_sync`, `submitted`, `verified`, `unknown`, `abandoned` |
| client_reference | UUID generated **on the device** |
| idempotency_key | derived from `client_reference` |
| terminal_result_code, terminal_message | |
| rrn, stan, trace_no | |
| masked_pan, card_issuer_code | |
| terminal_datetime | device clock — kept, not trusted |
| started_at, finished_at, submitted_at | |
| raw_result | jsonb, adapter output verbatim |

Unique: `(tenant_id, idempotency_key)`

**`payment_core_references`** — `id`, `payment_attempt_id`, `core_transaction_id`, `core_status`, `core_amount`, `verified_at`, `inquiry_count`, `last_inquiry_at`, `last_inquiry_error`, `raw_response jsonb`

> Payment Core is the source of financial truth. A `payment_attempts` row saying `terminal_success` is a *claim*; a verified `payment_core_references` row is *truth*. Reports use the latter.

**`payment_status_history`** — `id`, `payment_id`, `attempt_id`, `from_status`, `to_status`, `source` (`device`/`core`/`reconciliation`/`manual`), `actor_user_id`, `reason`, `occurred_at`, `correlation_id`

**`bill_payments`** — `id`, `tenant_id`, `payment_id`, `bill_identifier`, `payment_identifier`, `service_type`, `amount`, `company_name`

**`balance_inquiries`** — `id`, `tenant_id`, `merchant_id`, `store_id`, `device_id`, `terminal_id`, `performed_by_user_id`, `result_code`, `masked_pan`, `performed_at`

> Operational history only. Never appears in sales or financial reports (PAD §4.18).

**`qr_payment_requests`** — `id`, `tenant_id`, `payment_request_id`, `provider_code`, `provider_reference`, `qr_payload`, `expires_at`, `status`, `callback_received_at`, `inquiry_count`

*Defined now, built when O-06 resolves.*

**`idempotency_keys`** — the generic guard

`id`, `tenant_id`, `scope` (e.g. `payment.submit_result`), `key`, `request_hash`, `status` (`in_progress`/`completed`), `response_status`, `response_body jsonb`, `locked_at`, `completed_at`, `expires_at`

Unique: `(tenant_id, scope, key)`

> A replay with the same key and same body returns the stored response. Same key, **different** body returns `409 IDEMPOTENCY_KEY_REUSED` — that is a client bug and must be loud.

**`reconciliation_batches`** — `id`, `tenant_id`, `psp_code`, `business_date`, `source` (`file`/`api`), `total_external_count`, `total_external_amount`, `matched_count`, `mismatched_count`, `status`, `started_at`, `finished_at`

**`reconciliation_items`** — `id`, `batch_id`, `payment_attempt_id` (nullable), `external_reference`, `external_rrn`, `external_amount`, `external_status`, `match_status` (`matched`/`missing_local`/`missing_remote`/`amount_mismatch`/`status_mismatch`), `resolution` (`auto`/`manual`/`written_off`), `resolved_by_user_id`, `resolved_at`, `support_case_id`

---

### Cluster 8 — Customer Relationship & Consent

**`customers`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id | |
| **organization_id** | the scope boundary |
| mobile_e164 | |
| first_name, last_name | nullable |
| birth_date | date |
| gender, email | nullable |
| national_id_enc | nullable, encrypted |
| status | `active`, `blocked`, `deleted` |
| created_via | `pos`, `portal`, `import`, `api` |
| created_by_user_id, created_at | |

**Unique: `(organization_id, mobile_e164)`**

> This single constraint implements PAD §2.16. Customers are shared across the stores of one organization and never across organizations or tenants. A merchant with two branches sees one customer; two independent merchants sharing a phone number see two separate records.

**`customer_consents`** — `id`, `tenant_id`, `customer_id`, `purpose` (`service`/`marketing`), `channel` (`sms`/`push`/`bale`/`telegram`), `granted`, `source`, `granted_at`, `revoked_at`, `evidence jsonb`

Unique: `(customer_id, purpose, channel)`

> Service and marketing consent are separate (PAD §4.15). A transactional thank-you message and a campaign are different legal acts.

**`customer_purchases`** — the purchase relationship record

`id`, `tenant_id`, `customer_id`, `organization_id`, `merchant_id`, `store_id`, `device_id`, `terminal_id`, `cashier_user_id`, `invoice_id`, `payment_id`, `amount`, `occurred_at`

> Carries the full context required by PAD §2.16. This table, not `invoices`, is what customer analytics reads.

**`customer_stats`** — denormalised read model: `customer_id` (PK), `purchase_count`, `total_amount`, `avg_amount`, `first_purchase_at`, `last_purchase_at`, `last_store_id`, `updated_at`

> Updated by an event consumer, never in the payment path.

**`customer_labels`** — `id`, `tenant_id`, `organization_id`, `name`, `color`
**`customer_label_assignments`** — `customer_id`, `label_id`, `assigned_by_user_id`, `assigned_at`

**`customer_segments`** *(Phase 2 — schema defined, not built)* — `id`, `tenant_id`, `organization_id`, `name`, `definition jsonb`, `type` (`static`/`dynamic`), `last_computed_at`, `member_count`

**`customer_rfm_scores`** *(Phase 2)* — `customer_id`, `recency_score`, `frequency_score`, `monetary_score`, `segment_code`, `computed_at`, `thresholds_version`

---

### Cluster 9 — Messaging & Notification

**`notification_templates`** — `id`, `tenant_id` (nullable = system), `merchant_id` (nullable), `code`, `channel`, `locale`, `subject`, `body`, `variables jsonb`, `owner_level` (`system`/`tenant`/`merchant`), `approval_status` (`draft`/`pending`/`approved`/`rejected`), `approved_by_user_id`, `version`

**`message_rules`** — `id`, `tenant_id`, `merchant_id`, `trigger_event` (e.g. `PaymentSucceeded`), `template_id`, `channel`, `is_active`, `conditions jsonb`, `delay_seconds`, `created_by_user_id`

**`notification_requests`**

| Column | Notes |
|---|---|
| id, tenant_id, public_id | |
| merchant_id, customer_id, user_id | one of them |
| channel | `sms`, `push`, `bale`, `telegram`, `email`, `in_app`, `webhook` |
| category | `transactional`, `marketing`, `operational` |
| template_code, rendered_body | |
| recipient | |
| status | `queued`, `sending`, `sent`, `delivered`, `failed`, `suppressed` |
| suppression_reason | `no_consent`, `quota_exceeded`, `blocked`, `duplicate` |
| requested_by_module, bulk_job_id | |
| idempotency_key, correlation_id | |
| scheduled_at, created_at | |

**`notification_deliveries`** — `id`, `request_id`, `provider_code`, `provider_message_id`, `attempt_no`, `status`, `error_code`, `error_message`, `cost_amount`, `sent_at`, `delivered_at`, `callback_received_at`

**`bulk_message_jobs`** — `id`, `tenant_id`, `merchant_id`, `template_id`, `audience_type`, `audience_ref jsonb`, `total_recipients`, `sent_count`, `failed_count`, `suppressed_count`, `estimated_cost`, `actual_cost`, `approval_status`, `approved_by_user_id`, `status`, `scheduled_at`, `started_at`, `finished_at`, `created_by_user_id`

**`sms_credit_accounts`** — `id`, `tenant_id`, `merchant_id` (nullable = tenant-level pool), `type` (`prepaid`/`postpaid`), `balance_amount`, `currency`, `low_balance_threshold`, `status`

**`sms_credit_transactions`** — `id`, `account_id`, `type` (`topup`/`consume`/`refund`/`adjustment`), `amount`, `balance_after`, `reference_type`, `reference_id`, `performed_by_user_id`, `occurred_at`

> Balance updates take a row lock on the account. The hybrid model in PAD §4.16 (merchant credit / tenant credit / postpaid / packages) is expressed by `type` + which of `merchant_id`/`tenant_id` is set.

**`usage_counters`** — `id`, `tenant_id`, `merchant_id`, `feature_id`, `period_key` (`2026-08`), `used_count`, `limit_count`, `updated_at`, unique `(merchant_id, feature_id, period_key)`

---

### Cluster 10 — Bot Notifications (Telegram / Bale)

**`bot_links`** — `id`, `tenant_id`, `merchant_id`, `user_id`, `platform` (`telegram`/`bale`), `chat_id`, `link_token`, `status` (`pending`/`linked`/`revoked`), `linked_at`, `revoked_at`

Unique: `(platform, chat_id)`

**`bot_notification_preferences`** — `id`, `bot_link_id`, `event_type` (`payment_succeeded`, `payment_failed`, `acceptance_status_changed`, `device_offline`, `daily_summary`), `is_enabled`

> Linking flow: portal generates `link_token` → user opens `t.me/<bot>?start=<token>` → bot receives `chat_id` → token consumed, `bot_links` activated. The token is single-use and expires in 10 minutes.

---

### Cluster 11 — Integration & Provider Management

**`providers`** — `id`, `code`, `type` (`psp`/`sms`/`qr`/`bot`/`accounting`/`loyalty`), `name`, `adapter_class`, `status`, `health_status`, `last_health_check_at`

**`provider_configs`** — `id`, `provider_id`, `tenant_id` (nullable = platform default), `credential_ref`, `settings jsonb`, `priority`, `is_active`, `activated_by_user_id`

> `credential_ref` is a **pointer into the secret store**, never the secret. The database is not a vault.

**`integration_exchanges`** — the audit trail of every external call

`id`, `tenant_id`, `provider_id`, `direction` (`outbound`/`inbound`), `operation`, `request_payload jsonb`, `response_payload jsonb`, `http_status`, `duration_ms`, `status` (`success`/`failed`/`timeout`), `error_code`, `attempt_no`, `idempotency_key`, `correlation_id`, `occurred_at`

> Payloads are written through a **redaction pipeline** — PAN, CVV, IBAN, national ID, and credentials are masked before persistence. Partitioned monthly, retained 180 days.

**`inbound_webhooks`** — `id`, `provider_id`, `tenant_id`, `event_type`, `external_event_id`, `dedupe_key`, `signature_valid`, `raw_payload jsonb`, `status` (`received`/`processed`/`rejected`/`duplicate`), `processed_at`, `error`, `received_at`

Unique: `(provider_id, dedupe_key)`

**`webhook_endpoints`** — `id`, `tenant_id`, `url`, `secret_ref`, `subscribed_events jsonb`, `status`, `failure_count`, `disabled_at`, `created_by_user_id`

**`webhook_deliveries`** — `id`, `endpoint_id`, `event_id`, `event_type`, `attempt_no`, `status`, `response_code`, `response_body_snippet`, `next_retry_at`, `delivered_at`, `payload_hash`

**`dead_letters`** — `id`, `tenant_id`, `source` (`kafka`/`webhook`/`provider`), `topic_or_endpoint`, `payload jsonb`, `error`, `retry_count`, `last_retry_at`, `resolved_at`, `resolved_by_user_id`

---

### Cluster 12 — Events, Audit & Operations

**`outbox_events`** — the transactional outbox

| Column | Notes |
|---|---|
| id | bigserial |
| event_id | uuid, unique |
| tenant_id | |
| aggregate_type, aggregate_id | |
| event_type, event_version | |
| payload | jsonb |
| correlation_id, causation_id | |
| occurred_at | |
| published_at | null = not yet published |
| status | `pending`, `published`, `failed` |
| attempts, last_error | |

Index: `(status, id) WHERE status = 'pending'`

**`processed_events`** — `consumer_name`, `event_id`, `processed_at`, PK `(consumer_name, event_id)`

> Makes every consumer idempotent. Kafka gives at-least-once; this makes it effectively-once.

**`audit_logs`**

| Column | Notes |
|---|---|
| id, tenant_id | |
| actor_type | `user`, `agent`, `system`, `api_client`, `provider` |
| actor_user_id, on_behalf_of_user_id | impersonation captured here |
| api_client_id | |
| action | `merchant.suspend`, `role.permission.change`, … |
| resource_type, resource_id | |
| organization_id, merchant_id, store_id | scope context |
| channel | `web`, `pos`, `mobile`, `api`, `system` |
| ip_address, user_agent | |
| before, after | jsonb, redacted |
| result | `success`, `denied`, `error` |
| reason | |
| correlation_id | |
| occurred_at | |

Partitioned monthly. **Append-only** — no `UPDATE` or `DELETE` grant for `market_app`. Retention per §15.4.

Audited actions are exactly the PAD §4.28 list: agent impersonation, role/permission change, tenant settings change, merchant suspension, service activation, device binding, remote revoke, SMS template change, bulk send, unknown-status override, sensitive report download, domain change, POS build publication.

**`support_cases`** — `id`, `tenant_id`, `public_id`, `merchant_id`, `category`, `severity`, `status`, `subject`, `description`, `related_resource_type`, `related_resource_id`, `correlation_id`, `assigned_to_user_id`, `sla_due_at`, `opened_at`, `closed_at`, `opened_by` (`user`/`system`)

**`support_case_events`** — `id`, `case_id`, `type`, `note`, `actor_user_id`, `occurred_at`

**`files`** — `id`, `tenant_id`, `public_id`, `owner_type`, `owner_id`, `storage_key`, `original_name`, `mime_type`, `size_bytes`, `checksum_sha256`, `visibility` (`private`/`public`), `scan_status`, `uploaded_by_user_id`, `expires_at`

**`feature_flags`** — `id`, `code`, `tenant_id` (nullable), `is_enabled`, `rollout_percentage`, `conditions jsonb`, `updated_by_user_id`

---

### Cluster 13 — Agent Operations

**`agents`** — `id`, `tenant_id`, `user_id`, `code`, `status`, `region`, `manager_agent_id`, `joined_at`
**`leads`** — `id`, `tenant_id`, `agent_id`, `mobile_e164`, `business_name`, `business_category_id`, `province`, `city`, `source`, `status` (`new`/`contacted`/`qualified`/`converted`/`lost`), `lost_reason`, `converted_merchant_id`, `converted_at`
**`agent_assignments`** — `id`, `tenant_id`, `agent_id`, `merchant_id`, `assigned_at`, `assigned_by_user_id`, `unassigned_at`
**`agent_activities`** — `id`, `tenant_id`, `agent_id`, `lead_id`, `merchant_id`, `type` (`call`/`visit`/`note`/`follow_up`), `note`, `occurred_at`
**`onboarding_tasks`** — `id`, `tenant_id`, `merchant_id`, `agent_id`, `type`, `status`, `due_at`, `completed_at`

> Commission and referral attribution are **absent by design** — they belong to the Affiliate Platform (PAD §2.24), which is out of Market's boundary.

---

### Cluster 14 — Marketplace

**`marketplace_categories`** — `id`, `code`, `name`, `parent_id`, `sort_order`, `status`
**`marketplace_items`** — `id`, `public_id`, `category_id`, `title`, `description`, `images jsonb`, `price_display`, `external_url`, `vendor_name`, `status`, `approved_by_user_id`, `approved_at`
**`marketplace_item_visibility`** — `item_id`, `tenant_id`, `is_visible`, unique `(item_id, tenant_id)`
**`marketplace_leads`** — `id`, `tenant_id`, `merchant_id`, `item_id`, `contact_name`, `contact_phone`, `note`, `status`, `handled_by_user_id`, `created_at`
**`marketplace_interactions`** — `id`, `tenant_id`, `merchant_id`, `item_id`, `type` (`view`/`click`), `occurred_at` — partitioned monthly, 90-day retention

---

### Cluster 15 — Reporting read models

Materialised by event consumers, never written by the operational path.

**`daily_sales_aggregates`** — `tenant_id`, `merchant_id`, `store_id`, `business_date`, `invoice_count`, `gross_amount`, `discount_amount`, `net_amount`, `customer_linked_count`, `updated_at` — PK `(merchant_id, store_id, business_date)`

**`daily_payment_aggregates`** — `tenant_id`, `merchant_id`, `store_id`, `terminal_id`, `business_date`, `type`, `succeeded_count`, `succeeded_amount`, `failed_count`, `unknown_count`, `reversed_count`, `avg_amount`

**`merchant_funnel_snapshots`** — `tenant_id`, `merchant_id`, `snapshot_date`, `lifecycle_level`, `acceptance_status`, `has_active_terminal`, `first_transaction_at`, `days_to_activation`

**`device_status_snapshots`** — `tenant_id`, `device_id`, `snapshot_date`, `is_online`, `last_seen_at`, `pending_sync_count`, `app_version_code`

**`report_exports`** — `id`, `tenant_id`, `requested_by_user_id`, `report_code`, `parameters jsonb`, `status`, `file_id`, `row_count`, `is_masked`, `watermark_text`, `expires_at`, `downloaded_at`, `download_count`

> Every download is audited. Sensitive reports are watermarked with the requesting user's identity (PAD §3.11.4).

### 7.3 Table count summary

| Cluster | Tables |
|---|---|
| 1. Tenancy | 10 |
| 2. Identity & Access | 9 |
| 3. Merchant | 7 |
| 4. Acceptance | 5 |
| 5. Device & Terminal | 11 |
| 6. Sale & Invoice | 5 |
| 7. Payment | 11 |
| 8. Customer | 8 |
| 9. Messaging | 7 |
| 10. Bot | 2 |
| 11. Integration | 7 |
| 12. Events & Audit | 6 |
| 13. Agent | 5 |
| 14. Marketplace | 5 |
| 15. Reporting | 5 |
| **Total** | **~103** |

Of these, roughly **60 are required for Wave 1 + Wave 2** (§18) after the D-18 to D-21 scope
cuts — Cluster 10 (Bot, 2 tables) leaves the MVP build with D-20, and Cluster 14 (Marketplace,
5 tables) with D-21. Both stay **defined** here.

That is the deliberate trade in this document: the schema covers the three-to-five-year product,
the build covers the six-month one. Deferring a feature costs a table definition that goes
unused for a while; it does not cost a migration later.

---

## 8. Core state machines

### 8.1 Merchant Acceptance

```
                 ┌─────────┐
                 │  draft  │
                 └────┬────┘
                      │ complete + validate
                 ┌────▼─────────────────┐
                 │ ready_for_submission │
                 └────┬─────────────────┘
                      │ submit to PSP
                 ┌────▼──────┐
                 │ submitted │
                 └────┬──────┘
                      │ PSP ack
                 ┌────▼─────────┐
          ┌──────┤ under_review ├──────┐
          │      └────┬─────────┘      │
          │           │                │
 ┌────────▼─────────┐ │        ┌───────▼────┐
 │correction_required│ │        │  rejected  │ (terminal)
 └────────┬─────────┘ │        └────────────┘
          │ resubmit  │ approve
          └───────────┤
                 ┌────▼─────┐
                 │ approved │
                 └────┬─────┘
                      │
            ┌─────────▼───────────┐
            │terminal_provisioning│
            └─────────┬───────────┘
                      │ terminal active
                 ┌────▼──────┐        ┌───────────┐
                 │ activated ├───────►│ suspended │
                 └───────────┘◄───────┴───────────┘
```

Rules:
- External PSP status is **never** stored as the application status. It is mapped to a canonical value; the raw value lives in `psp_submissions.external_status`.
- Every transition writes `acceptance_status_history` with its source (`internal` / `psp_webhook` / `psp_polling`).
- `approved` emits `AcceptanceApproved`, which is what raises the merchant's lifecycle level. Acceptance never writes to `merchants` directly.

### 8.2 Payment

```
  payment_requests:  created ──► dispatched ──► in_progress ──► completed
                          └──► expired    └──► cancelled

  payments:          pending ──┬──► succeeded ──► reversed
                               ├──► failed
                               ├──► cancelled
                               └──► unknown ──┬──► succeeded
                                              └──► failed
```

`unknown` is the state that matters. Entered when:
- the terminal returned success but the backend never confirmed, **or**
- the backend never received a result within the timeout, **or**
- Payment Core returned an indeterminate response.

Exits only via: Payment Core inquiry, daily reconciliation, or an audited manual override with `payment.unknown.override` permission.

**While a payment is `unknown`, the invoice is locked** (`payment_status = locked_unknown`). No new payment attempt is permitted on that invoice. This prevents double charging — the single most damaging failure mode in this product.

### 8.3 Payment attempt (device-side)

```
 started ──► terminal_success ──► pending_sync ──► submitted ──► verified
      │                                 ▲               │
      │                                 └───retry───────┘
      └──► terminal_failed ──► pending_sync ──► submitted
```

`pending_sync` rows live in the device's encrypted Room database and are **never** deleted until the server acknowledges with a terminal state.

### 8.4 Device lifecycle

```
 (first launch) ──► registered ──► bound ──► active
                                     │
                                     ├──► blocked (remote revoke)
                                     └──► retired
```

### 8.5 Invoice

```
 draft ──► finalized ──► (void, audited, only if unpaid)
```

Amount changes after finalisation are **not** edits — they create a new invoice or a correction document (PAD §4.13). A retry after a failed payment reuses the same invoice with a new `payment_attempt`.

---

## 9. Payment flows

### 9.1 Manual payment on POS (the primary path)

```
Cashier          POS App              Backend            Terminal        Payment Core
  │                 │                    │                   │                │
  ├─ enter amount ─►│                    │                   │                │
  │                 ├─ POST /payment-requests ───────────────►│                │
  │                 │   {amount, merchant, store, device,     │                │
  │                 │    terminal, idempotency_key,           │                │
  │                 │    correlation_id}                      │                │
  │                 │◄─ 201 {payment_request_id} ─┤           │                │
  │                 │                    │                   │                │
  │                 ├─ write attempt(started) to local DB     │                │
  │                 ├─ invoke terminal ─────────────────────►│                │
  │                 │◄─ result (rrn, stan, code) ────────────┤                │
  │                 ├─ PERSIST LOCALLY (durable, committed)   │                │
  │                 ├─ print receipt (allowed now)            │                │
  │                 │                    │                   │                │
  │                 ├─ POST /payment-attempts/{id}/result ───►│                │
  │                 │   Idempotency-Key: <client_reference>   │                │
  │                 │                    ├─ verify ──────────────────────────►│
  │                 │                    │◄─ confirmed ───────────────────────┤
  │                 │◄─ 200 {status: succeeded} ─┤           │                │
  │                 ├─ mark local row synced                  │                │
```

**The invariant:** the local write happens *before* the network call and is durable. If the app is killed, the device loses power, or the network dies, the result survives and `SyncWorker` retries with the same idempotency key.

### 9.2 Backend unreachable after terminal success

Per PAD §4.11.1:

1. Result committed to local ledger.
2. Attempt held as `pending_sync`.
3. Receipt printable, annotated **"در انتظار همگام‌سازی"**.
4. UI shows a pending-sync indicator with a count.
5. `SyncWorker` retries with exponential backoff (5s → 15s → 60s → 5m → 15m, capped).
6. Backend deduplicates on the idempotency key.
7. Backend queries Payment Core for the authoritative status.
8. Resolves to `succeeded` / `failed` / `unknown`.

**A device with pending-sync rows older than a threshold is blocked from starting new payments.** Configurable per tenant, default 20 items or 60 minutes. Without this, a device that has silently lost connectivity accumulates unreconciled money.

### 9.3 Server-initiated payment (portal / partner API)

The POS polls. Polling is retained because some terminals cannot hold a persistent connection (PAD §4.12).

```
GET /api/v1/pos/payment-requests/next
  → 204 No Content        (nothing pending)
  → 200 {request}         (claimed, status → dispatched)
```

- Long-poll with a 25-second server-side hold to reduce request volume.
- Claiming uses `SELECT ... FOR UPDATE SKIP LOCKED` so two devices never claim the same request.
- Requests expire after `expires_at` and are released.
- FCM push as an optional accelerator later; **polling remains the contract**.

### 9.4 Idempotency

| Operation | Key source |
|---|---|
| Create payment request | Client-generated UUID |
| Submit attempt result | Device `client_reference` |
| Finalise invoice | Client-generated UUID |
| PSP submission | `application_id` + attempt number |
| Notification send | `request_id` |
| Inbound webhook | Provider event id (`dedupe_key`) |

Middleware handles it uniformly: acquire row in `idempotency_keys` with `status = in_progress` via `INSERT ... ON CONFLICT DO NOTHING`. If the insert loses, either return the stored response (completed) or `409` with `Retry-After` (in progress).

### 9.5 Reconciliation

Daily, plus event-driven for `unknown` payments.

1. Ingest PSP settlement data for business date D-1.
2. Match on `rrn` + `terminal_no` + `amount`.
3. Classify: `matched`, `missing_local`, `missing_remote`, `amount_mismatch`, `status_mismatch`.
4. Auto-resolve `unknown` payments that appear as successful externally.
5. Open a `support_case` for everything else.
6. Emit `ReconciliationMismatchDetected`.

Reconciliation **may** move a payment out of `unknown`. It may **never** alter a `succeeded` or `failed` payment without an audited manual action.

---

## 10. Eventing

### 10.1 Two different mechanisms — do not conflate

| Mechanism | Purpose | Transport |
|---|---|---|
| **Laravel queues** | In-process async work: send an SMS, generate a PDF, call a provider | Redis |
| **Domain events** | Facts other modules react to | Outbox → Kafka |

A module publishes a domain event. It does not know or care who consumes it.

### 10.2 Transactional outbox

The application **never writes to Kafka directly**. It writes to `outbox_events` in the same transaction as the state change. A relay publishes to Kafka.

```php
DB::transaction(function () use ($cmd) {
    $payment = $this->payments->markSucceeded($cmd);
    $this->outbox->publish(new PaymentSucceeded($payment));   // same tx
});
```

Why this matters here: it decouples correctness from Kafka availability. If Kafka is down, payments still complete and events accumulate — a Kafka incident becomes an inconvenience rather than an outage.

It also means **the transport is swappable.** If a Kafka cluster is not already operated by the platform team, start the relay against Redis Streams or RabbitMQ and switch later. Zero application code changes.

**Relay design:** a leader-elected worker (Redis lock) polls `outbox_events WHERE status = 'pending' ORDER BY id LIMIT 500`, publishes, marks published. At-least-once by design; consumers deduplicate through `processed_events`.

### 10.3 Topic layout

```
market.merchant.v1        MerchantCreated, MerchantProfileCompleted,
                          MerchantActivated, MerchantSuspended
market.acceptance.v1      AcceptanceSubmitted, AcceptanceStatusChanged,
                          AcceptanceApproved, AcceptanceRejected
market.device.v1          DeviceRegistered, DeviceBound, TerminalActivated,
                          DeviceRevoked
market.sale.v1            InvoiceFinalized, InvoiceVoided
market.payment.v1         PaymentRequested, PaymentAttemptStarted,
                          PaymentSucceeded, PaymentFailed, PaymentUnknown,
                          PaymentUnknownResolved, PaymentReversed
market.customer.v1        CustomerCreated, CustomerPurchaseRecorded,
                          ConsentChanged
market.notification.v1    NotificationRequested, NotificationDelivered,
                          NotificationFailed
market.identity.v1        UserJoinedTenant, RoleAssigned, PermissionChanged
market.integration.v1     WebhookDeliveryFailed, ProviderHealthChanged
market.reconciliation.v1  ReconciliationMismatchDetected
```

- Partition key: `tenant_id:aggregate_id` — guarantees per-aggregate ordering.
- `v1` in the topic name. A breaking change means `v2` alongside, with a deprecation window.
- Retention: 7 days for operational topics, 30 days for payment.

### 10.4 Envelope

```json
{
  "event_id": "uuid",
  "event_type": "PaymentSucceeded",
  "event_version": 1,
  "occurred_at": "2026-08-24T09:12:33.412Z",
  "tenant_id": 42,
  "aggregate_type": "Payment",
  "aggregate_id": "uuid",
  "correlation_id": "uuid",
  "causation_id": "uuid",
  "actor": { "type": "user", "id": "uuid" },
  "payload": { }
}
```

Rules: additive changes only within a version; **no PII in payloads** — identifiers only, consumers fetch what they need; `tenant_id` is mandatory and asserted by consumers.

### 10.5 Consumers in MVP

| Consumer | Reacts to | Does |
|---|---|---|
| `CustomerStatsProjector` | `CustomerPurchaseRecorded` | Updates `customer_stats` |
| `ReportingProjector` | payment, sale, device events | Updates daily aggregates |
| `MessagingTrigger` | payment, acceptance, merchant events | Evaluates `message_rules` |
| ~~`BotNotifier`~~ | — | Out of MVP per D-20. The topics it would consume already exist, so adding it later needs no change here |
| `WebhookDispatcher` | all subscribed events | Delivers to partner endpoints |
| `EntitlementRecalculator` | lifecycle, acceptance, terminal events | Recomputes access decisions |
| `AuditProjector` | sensitive events | Ensures audit completeness |

---

## 11. Integration layer

### 11.1 Adapter contract

Every provider sits behind an interface. The domain never sees a provider's model.

```php
interface PspAcceptanceAdapter {
    public function submitApplication(CanonicalApplication $a): SubmissionResult;
    public function inquireStatus(string $psp_reference): AcceptanceStatusResult;
    public function parseWebhook(Request $r): ?AcceptanceStatusResult;
    public function verifySignature(Request $r): bool;
}

interface TerminalProvisioningAdapter {
    public function requestTerminal(TerminalRequest $r): ProvisioningResult;
    public function inquireTerminal(string $reference): TerminalStatusResult;
}

interface PaymentCoreClient {
    public function verify(VerifyCommand $c): PaymentVerification;   // sync today
    public function inquire(string $reference): PaymentVerification; // recovery
}
```

`PaymentVerification` carries `succeeded | failed | pending | unknown`. **`pending` is in the contract from day one** — that is the async fallback for D-08. If Payment Core becomes asynchronous, the state machine already handles it and only the adapter changes.

### 11.2 Resilience policy

| Control | Setting |
|---|---|
| Connect timeout | 3s |
| Read timeout | 10s (payment), 30s (documents) |
| Retries | 3, exponential + jitter, **idempotent operations only** |
| Circuit breaker | Open after 5 failures in 60s; half-open after 30s |
| Bulkhead | Separate connection pool per provider |
| Fallback | Queue for retry, never fail the user-facing operation for non-critical providers |

### 11.3 Failure isolation

Hard rule (PAD §1.21): **no non-payment provider may block a payment.**

| Provider | Failure behaviour |
|---|---|
| Payment Core | Blocks — payment enters `unknown`, recovery engages |
| PSP acceptance | Does not block payment; application stays `submitted` |
| SMS | Never blocks; queued, retried, degrades silently |
| Telegram / Bale | Never blocks; fire-and-forget with retry |
| QR provider | Blocks QR only; card payment unaffected |
| Marketplace catalog | Degrades to cached or empty state |

### 11.4 Iran Kish (O-01)

The specification is not available yet. Mitigation:

1. Define the canonical model and adapter interface **now** — done above.
2. Build a `FakePspAdapter` with a configurable state machine covering approve, reject, correction-required, and timeout.
3. Develop and test the entire acceptance journey against the fake.
4. When the spec arrives, implement `IranKishAdapter` — an estimated 1–2 week task that touches no domain code.

This removes O-01 from the critical path. It also means the fake adapter is the demo environment, which is separately useful.

**Watch item:** the PRD risk register notes PSP status may be **polling-only** with rate limits. The design must not assume webhooks. `AcceptanceStatusPoller` runs as a scheduled job with per-PSP rate limiting and exponential backoff per application; webhook support is an optimisation layered on top.

---

## 12. Android POS architecture

### 12.1 Layering

```
┌──────────────────────────────────────────────┐
│  UI (Compose / Views)                        │
├──────────────────────────────────────────────┤
│  ViewModels                                  │
├──────────────────────────────────────────────┤
│  Use Cases  (payment, invoice, customer …)   │
├──────────────────────────────────────────────┤
│  Repositories                                │
├───────────────┬──────────────┬───────────────┤
│ Room (SQLCipher)│ Retrofit    │ Adapters     │
│  - ledger      │  - API      │  - Terminal   │
│  - queue       │             │  - Printer    │
└───────────────┴──────────────┴───────────────┘
```

**POS Product Core is shared across all tenants and PSPs.** Only the adapter implementations and the build profile differ. This is the mechanism that prevents the fork the PAD forbids.

### 12.2 Device identity and session

- On first launch: derive `device_uid` from stable hardware identifiers, generate a keypair in the Android Keystore, register with the backend.
- Device is `registered` but unusable until bound via activation code or portal approval.
- Two token layers: a **device token** (long-lived, bound to the keystore key) and a **user session token** (short-lived, per cashier).
- Every request carries both. Remote revoke invalidates the device token, and the device is locked at the next call.
- Cashier switching does not require re-registering the device.

### 12.3 Terminal adapter (O-02)

```kotlin
interface TerminalAdapter {
    suspend fun isAvailable(): Boolean
    suspend fun capabilities(): TerminalCapabilities
    suspend fun purchase(request: TerminalPurchaseRequest): TerminalResult
    suspend fun balanceInquiry(): TerminalResult
    suspend fun billPayment(request: BillRequest): TerminalResult
    suspend fun lastTransaction(): TerminalResult?   // critical for recovery
}
```

`lastTransaction()` is not optional. When the app crashes mid-payment, this is how it learns what actually happened on the terminal.

Until O-02 resolves, implement `MockTerminalAdapter` with configurable success, failure, timeout, and crash-during-payment behaviours. All recovery logic is testable without hardware.

### 12.4 Local durable ledger

```kotlin
@Entity(tableName = "payment_attempts")
data class LocalPaymentAttempt(
    @PrimaryKey val clientReference: String,  // UUID, also the idempotency key
    val paymentRequestId: String?,
    val amount: Long,
    val status: String,
    val terminalResultJson: String?,
    val createdAt: Long,
    val syncAttempts: Int,
    val lastSyncError: String?,
    val syncedAt: Long?
)
```

- SQLCipher-encrypted; key in the Android Keystore.
- Rows are **never deleted** until the server acknowledges a terminal state, then retained 30 days for reprints.
- WAL mode; the terminal result is committed before any UI transition.

### 12.5 Sync worker

`WorkManager` periodic (15 min) + expedited on connectivity regain:

1. Select `pending_sync` rows, oldest first.
2. POST with the idempotency key.
3. On `2xx` → mark synced. On `4xx` (non-409) → mark permanently failed, raise alert. On `409` → treat as success. On `5xx`/network → back off, increment attempts.
4. After N failures, surface a visible warning and block new payments.

### 12.6 OTA

1. `GET /api/v1/pos/releases/check?version_code=X` → available release + `sha256` + `force`.
2. Download to app-private storage, verify SHA-256 **and** signature before install.
3. If `force_update` → block operations, prompt immediately.
4. Otherwise prompt at a safe moment (no active payment, no pending sync).
5. Report `update_status` back to the server.

**Open (O-03):** whether silent install is available depends on device-owner provisioning or system signing, which is PSP-dependent. Assume user-confirmed install; treat silent as an optimisation.

**Force-update policy** — an unresolved question from the PRD. Recommended: never interrupt an in-flight payment; block *new* payments and allow the current one to complete; a device with pending-sync rows must sync before updating.

---

## 13. Unified Portal architecture

### 13.1 Workspaces, not applications

One Nuxt application. Workspace is a routing and navigation concern driven by the user's resolved capabilities:

```
/platform/*      Platform Operations
/partner/*       Partner Management
/merchant/*      Merchant Management
/agent/*         Agent Operations
/store/*         Store Operations
/support/*       Support Operations
/finance/*       Finance Operations
```

`GET /api/v1/me/context` returns memberships, roles, scopes, entitlements, active workspace options, and the branding bundle. Navigation is generated from it. The user switches workspace or merchant without re-authenticating.

### 13.2 Access in the UI

The UI reflects decisions; it does not make them. Each feature carries an `AccessDecision` from the backend, and the component renders per status:

| Status | UI |
|---|---|
| `Active` | Normal |
| `Limited` | Normal with a quota indicator |
| `Preview` | Read-only with a "not available on your plan" note |
| `Preparation` | Call-to-action to complete the prerequisite |
| `Locked` | Disabled with reason and next step |
| `Hidden` | Not rendered |
| `WaitingForData` | Empty state explaining what generates the data |

This is Progressive Access made visible, and it is the difference between "the button does nothing" and "here is what to do next."

### 13.3 Branding

Resolved by hostname before the app mounts, applied as CSS custom properties, cached with an ETag. No partner-specific build.

---

## 14. API conventions

### 14.1 Surfaces

| Surface | Base | Auth |
|---|---|---|
| Channel API | `/api/v1/*` | User bearer token |
| POS API | `/api/v1/pos/*` | Device token + user session token |
| Partner API | `/api/partner/v1/*` | OAuth2 client credentials |
| Provider callbacks | `/api/callbacks/{provider}` | Signature verification |
| Admin API | `/api/v1/admin/*` | User token + platform scope |

### 14.2 Standards

- URI versioning (`/v1`). Additive changes only within a version.
- `snake_case` JSON. UUIDs externally, never integer IDs.
- Money as integer Rial with an explicit `currency`.
- Timestamps ISO-8601 UTC.
- Cursor pagination (`?cursor=&limit=`) — offset pagination degrades badly on the tables that will grow.
- Required headers: `X-Correlation-Id` (generated if absent), `Idempotency-Key` on all unsafe operations, `X-Tenant-Slug` for POS and partner.

### 14.3 Errors

```json
{
  "error": {
    "code": "PAYMENT_ALREADY_IN_PROGRESS",
    "message": "پرداخت دیگری برای این فاکتور در حال انجام است.",
    "details": { "payment_id": "uuid" },
    "correlation_id": "uuid"
  }
}
```

Codes are stable and documented; messages are localised and may change. Clients branch on codes only.

| Status | Meaning |
|---|---|
| 400 | Malformed request |
| 401 | Not authenticated |
| 403 | Authenticated, not permitted |
| **423** | **Feature locked — Progressive Access. Includes `next_step_url`** |
| 409 | Conflict / idempotency violation |
| 422 | Validation failure |
| 429 | Rate limited |

### 14.4 Rate limits

| Surface | Limit |
|---|---|
| Authenticated user | 300/min |
| POS device | 120/min, payment endpoints 30/min |
| Partner API | Per contract tier |
| OTP / verification | 5/hour per mobile, 20/hour per IP |
| Report export | 10/hour per user |

---

## 15. Security, observability and compliance

### 15.1 Data classification

| Class | Examples | Handling |
|---|---|---|
| Critical | IBAN, national ID, KYC documents | Encrypted at rest (column-level), masked in all responses, access audited |
| Sensitive | PAN (masked only), customer mobile, addresses | Never stored in full for PAN; masked in exports by default |
| Internal | Amounts, merchant data, device data | Tenant-isolated |
| Public | Marketplace catalog, branding | — |

**Full PAN, CVV, PIN, and track data are never received, logged, or stored.** The masked PAN from the terminal is the only card data that enters the system.

### 15.2 Security controls

- TLS 1.2+ everywhere; certificate pinning on Android.
- Argon2id for passwords under the Sanctum driver.
- Column encryption for critical fields with keys in the secret store, not the database.
- Provider credentials stored as references only.
- Webhook signature verification with timestamp tolerance and replay protection via `dedupe_key`.
- All destructive and financial actions require an explicit permission and write an audit row.
- Break-glass access is a distinct role, time-boxed, alerting on use.

### 15.3 Observability

| Signal | Tool | Requirement |
|---|---|---|
| Logs | Loki | Structured JSON, `tenant_id` + `correlation_id` on every line, PII redacted |
| Metrics | Prometheus | RED per endpoint, payment success rate, sync lag, outbox depth, provider latency |
| Traces | OpenTelemetry | Full journey trace on payment paths |
| Errors | Sentry | Grouped by tenant |

**Alerts that matter most:**

| Alert | Threshold |
|---|---|
| Payment success rate drop | < 90% over 10 min |
| `unknown` payment count | > 5 in 15 min |
| Outbox backlog | > 5,000 pending or > 5 min old |
| Devices with pending sync | > 10 devices with items > 30 min |
| Provider circuit open | Any |
| Reconciliation mismatch | > 1% of daily volume |
| RLS violation attempt | Any — treat as a security incident |

### 15.4 Retention

| Data | Retention |
|---|---|
| Payments, invoices, reconciliation | 10 years (financial) |
| Audit logs | 2 years hot, then archive |
| Integration exchanges | 180 days |
| Device health / logs | 90 days |
| Marketplace interactions | 90 days |
| Report exports | 7 days, then purged |

*Subject to the compliance answer under O-05.*

---

## 16. Environments, delivery and testing

### 16.1 Environments

| Env | Purpose | Data |
|---|---|---|
| Local | Development | Seeded, all providers faked |
| CI | Automated tests | Ephemeral |
| Staging | Integration + UAT | Anonymised, PSP sandbox |
| Production | Live | — |

### 16.2 Migration discipline

- Expand/contract only. No destructive migration in the same release as the code that stops using a column.
- Every migration reversible or explicitly flagged irreversible with a written rollback plan.
- Zero-downtime rule: add nullable → backfill in batches → switch code → make non-null → drop old, across separate releases.

### 16.3 Release

- Trunk-based, short-lived branches.
- Backend: rolling deploy, readiness probes, feature flags for anything user-visible.
- Portal: static build to CDN, versioned assets.
- POS: staged rollout by percentage → tenant → all, with a rollback release always prepared.

### 16.4 Minimum test gate

Non-negotiable:

| Layer | Requirement |
|---|---|
| Unit | All domain state machines, entitlement engine, money arithmetic |
| Integration | Every module contract, RLS isolation (a test that asserts tenant A cannot read tenant B) — **and the same assertion executed inside a queued job**, which is where tenant context is most easily lost (§5.6) |
| Static | `deptrac` boundary check; a schema test asserting no foreign key crosses a module boundary (§7.1) |
| Contract | Every provider adapter against its fake |
| E2E | The four critical journeys: registration→acceptance→device→payment; payment with backend loss; invoice→payment→customer link; agent-assisted onboarding |
| Android | Instrumented tests for ledger durability and sync retry — including process death mid-payment |
| Load | Payment endpoints at 3× expected peak before launch |

**The RLS isolation test and the process-death test are the two that will save the project.** Both should exist before any merchant sees the system — and the RLS test must run in both a request and a job context, because passing in one proves nothing about the other.

---

## 17. Non-functional requirements

| Requirement | Target |
|---|---|
| Payment request creation (p95) | < 300 ms |
| Payment result submission (p95) | < 500 ms excluding Payment Core |
| Portal page interactive (p95) | < 2.5 s |
| POS payment screen ready | < 1 s from tap |
| Availability — payment path | 99.9% |
| Availability — portal | 99.5% |
| RPO | 5 minutes (WAL streaming) |
| RTO | 1 hour |
| Payment result durability | **100% — no acknowledged terminal result may be lost** |
| Concurrent devices | Sized after O-04 |

The durability line is the only NFR with no acceptable failure rate. Everything else can degrade.

---

## 18. Delivery plan

Sequenced to front-load what is expensive to change later.

### Wave 0 — Foundations (weeks 1–3)

Repository and module skeleton with `deptrac` enforcement · Kubernetes manifests, CI/CD · PostgreSQL with RLS and the tenant-context middleware · `AuthDriver` abstraction with both implementations · permission registry and `AccessDecisionService` skeleton · outbox + relay · correlation ID propagation · Nuxt shell with the design system and tenant theming · Android skeleton with device registration.

*Exit criterion: a request enters, resolves a tenant, authenticates, authorises, writes with RLS active, and emits an event.*

### Wave 1 — Merchant to first payment (weeks 4–12) — **the release-defining wave**

Tenancy and branding (basic) · identity, membership, roles, scopes · organization / merchant / store · agent lite (lead, assignment, assisted registration) · acceptance with `FakePspAdapter` · device registration, binding, terminals, capabilities · POS: login, calculator, manual payment, receipt printing, local ledger, sync, recovery · payment orchestration, idempotency, `unknown` handling · simple invoice with numbering, template, print · audit logging · OTA.

*Exit criterion: a merchant registers, is accepted, binds a device, takes a card payment, prints an invoice, and the result survives a forced network outage and a process kill.*

### Wave 2 — Merchant value (weeks 13–18)

Customer management, consent, purchase linking, `customer_stats` · messaging: templates, rules, thank-you SMS, manual and bulk send, credit and quota · dashboards and core operational reports · report export with masking and audit · entitlement and service catalog UI · partner management basics · Iran Kish adapter replacing the fake (O-01 dependent).

*Cut from this wave by D-20:* Telegram/Bale bot linking and notifications.

### Wave 3 — Platform hardening (weeks 19–22)

Reconciliation, mismatch handling, support cases · partner API and outbound webhooks · white-label build pipeline and staged OTA (O-03 dependent) · security review, load testing, runbooks, pilot onboarding.

*Cut from this wave by D-21:* marketplace (catalog + redirect + leads).

### Weeks 23–26 — Contingency

**Unallocated, and deliberately so.** The previous version of this plan filled all 26 weeks with
committed work, which meant every open decision, every integration surprise and every wrong
estimate came directly out of the launch date. Four weeks — roughly 15% — is the minimum honest
buffer for a plan with five open decisions and two unbuilt external integrations.

If the buffer is not needed, Wave 3's deferred items (marketplace, bots) are the first things to
pull forward. It is not spare capacity to be scheduled in advance.

### Explicitly deferred

QR payment · campaigns · loyalty, credit, **RFM (D-19)** · financial and lending services ·
accounting connectors · biolink · mobile management app · analytical reporting · **product
catalog, inventory, cart, discount engine, pricing, procurement (D-18)** · advanced cash
management and shifts · **bot notifications (D-20)** · **marketplace (D-21)**.

**Tax services are not built by Market in MVP** (D-10). Note this is a change from v0.1, which
removed them from Market's scope entirely — that conflicted with PAD §2.22, which defines Tax
Services as a bounded context. The context stands at platform level and is deferred; Market
consumes it later through the Integration Hub.

### Critical path

```
Wave 0 ──► Identity/Tenancy ──► Merchant ──► Acceptance ──► Device ──► Payment ──► GO/NO-GO
                                                   ▲
                                            O-01 (Iran Kish spec)
                                            O-02 (terminal integration)
```

O-01 and O-02 are blockers on the *integration* path, and both are neutralised by the
fake-adapter strategy in §11.4 and §12.3 — provided that work starts in Wave 0.

**O-07 (Payment Core inquiry/verify) is not neutralised by anything**, which is why v0.1 was
wrong to call O-01 and O-02 "the only true external blockers." A fake adapter substitutes for an
integration you have not built yet. It cannot substitute for a capability that does not exist in
a real upstream system. If Payment Core has no inquiry endpoint, `unknown` payments cannot be
resolved automatically, §9.5 reconciliation becomes permanently manual, and that is an operating
cost for the life of the product — not a Wave 3 task. Hence its week-2 date: it is the one open
item that should be answered before Wave 1 starts.

---

## 19. Assumptions requiring confirmation

| # | Assumption |
|---|---|
| A-01 | The existing PayStar CI/CD, observability, and secret-management stack can be reused as-is |
| A-02 | A Kafka cluster is already operated by the platform team. If not, see §10.2 |
| A-03 | PayStar Auth exposes OIDC-compatible endpoints with JWKS; otherwise the Sanctum driver is the MVP default |
| A-04 | ~~Payment Core exposes an inquiry/verify endpoint~~ — **promoted to O-07** (§1.2). It was too consequential to sit in a list of things nobody owns |
| A-05 | PSP settlement data is available daily in a machine-readable form |
| A-06 | The existing Sepehr/Oxin POS codebase is a reference implementation, not a starting point (pending O-02) |
| A-07 | Currency is IRR throughout; Toman is a display concern only |
| A-08 | MVP targets a single Iran Kish terminal family |
| A-09 | No PCI-DSS certification is in scope, since no card data is handled |
| A-10 | Partner self-service onboarding is out of MVP; tenants are created by PayStar operations |
| A-11 | **Team composition.** This plan assumes one backend engineer plus a technical lead (§3.1), one frontend engineer, and one Android engineer, each available approximately full-time for the whole 26 weeks. **The entire delivery plan rests on this and it was previously unstated.** If the real team is smaller, or any role is shared with another product, §18 does not hold and scope must come out of Wave 2 before Wave 1 starts — not discovered in week 14 |
| A-12 | The PAD's MVP scope (§1.30) is the authoritative one where it conflicts with the PRD (§1.3), and stakeholders accept that the PRD describes a later horizon |

---

## 20. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| Iran Kish spec unavailable (O-01) | High | Fake adapter; canonical contract first |
| Terminal integration unknown (O-02) | High | `TerminalAdapter` abstraction; mock implementation |
| No Payment Core inquiry (A-04) | High | Manual reconciliation workflow as fallback; confirm early |
| Payment result loss | Critical | Local durable ledger, idempotency, recovery, reconciliation, dedicated tests |
| Cross-tenant leakage | Critical | RLS at the database, automated isolation test in CI |
| Kafka operational burden | Medium | Outbox insulates the application; transport is swappable |
| POS hardware limits (Android 8, 2 GB) | Medium | Minimal local state, no heavy SDKs, test on the oldest target device |
| Double charging | Critical | Invoice lock on `unknown`, idempotency keys, device debounce |
| PSP polling rate limits | Medium | Per-application backoff, prioritised queue |
| White-label build sprawl | Medium | Single core + build profiles; no branches |
| Insufficient regression coverage | High | §16.4 test gate enforced in CI as a merge blocker |
| Team capacity below A-11 | **Critical** | A-11 states the assumption explicitly; confirm before Wave 0 exit. Wave 2 is the designated scope-release valve |
| No Payment Core inquiry endpoint (O-07) | **Critical** | Answer by week 2. If absent: manual reconciliation is a permanent operating cost and must be staffed, not engineered around |
| Scope re-expansion from the PRD | High | §1.3 records the resolution and its rationale; any reversal is a dated decision with a named owner, not a silent reinterpretation |
| Stack drifts back onto EOL versions | Medium | D-03/D-13 pinned to supported majors; add a dependency-freshness check to CI so the next EOL is noticed before it lands |

---

## 21. Next steps

Ordered by how much a late answer costs.

1. **Confirm A-11 (team composition).** The delivery plan is arithmetic on top of it. If it is
   wrong, nothing else in §18 means anything. Before Wave 0 exit.
2. **Answer O-07 (Payment Core inquiry/verify) — week 2.** Determines whether reconciliation is
   an engineering problem or a permanent staffing cost.
3. **Confirm §1.3's scope resolution with product and business stakeholders.** This document now
   builds the PAD's MVP, not the PRD's. That needs to be an agreed position, not an
   architectural fait accompli.
4. Confirm or correct A-02 and A-03 — the remaining assumptions that change structure.
5. Obtain the Iran Kish specification (O-01) and the terminal integration decision (O-02).
6. Provide the compliance constraints (O-05) before the acceptance module is built, since they
   affect encryption and retention.
7. **Re-export the PRD from source** and re-check §1.3 against it (§1.3, closing note).
8. Approve this document, then produce the API specification and the Android technical design as
   separate documents.

---

*End of document — v0.2 Draft*
