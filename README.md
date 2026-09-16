# PayStar Market — backend

A Laravel modular monolith: one deployable artifact with hard internal boundaries,
so any module could later be extracted into a service without rewriting its callers.

This repository is backend-only. The Nuxt portal and the Kotlin POS application live
in their own repositories; no commit here spans backend and client.

At the time of writing the modules are empty. That is deliberate — the boundary
enforcement had to predate the code it guards, because enforcement retrofitted onto
existing modules is a far harder and far more contested job.

---

## Requirements

| | |
|---|---|
| PHP | 8.4 or newer (`composer.json` pins `^8.4`) |
| Composer | 2.x |

No database is required to run the checks or the test suite.

## Getting started

```bash
composer install
composer guard        # all three boundary checks
php artisan test      # the suite, including the guard fixtures
```

That is the whole setup. `composer install` is enough for every command in this
README to work on a clean checkout.

---

## Module map

Nineteen modules, verbatim from the architecture (TAD §4). **Do not add a twentieth
without changing the architecture document first** — and if you do add one, see
[Adding a module](#adding-a-module).

| Module | Owns |
|---|---|
| `Platform` | tenant context, RLS, correlation, base contracts |
| `Identity` | membership, roles, permissions, scopes, delegation |
| `Tenancy` | tenant, branding, domains, offerings, entitlements |
| `Merchant` | organization, merchant, store, profiles |
| `Acceptance` | applications, documents, PSP submission, provisioning |
| `Agent` | leads, assignments, activities, tasks |
| `Device` | devices, bindings, terminals, capabilities, sessions |
| `Release` | app releases, build profiles, OTA targeting |
| `Sale` | invoices, items, numbering, templates, printing |
| `Payment` | requests, payments, attempts, recovery, reconciliation |
| `Customer` | customers, consent, purchases, labels, stats |
| `Messaging` | templates, rules, bulk jobs, credit |
| `Notification` | delivery, providers, status |
| `Bot` | Telegram / Bale linking and push |
| `Marketplace` | catalog, visibility, leads |
| `Reporting` | read models, aggregates, exports |
| `Integration` | provider registry, adapters, webhooks, DLQ |
| `Audit` | audit log, support cases, incidents |
| `Api` | partner / provider API surface, webhooks out |

### Layering inside a module

Every module has the same nine directories (TAD §4.2):

```
app/Modules/Payment/
  Contracts/        # public interfaces + DTOs — the ONLY part other modules may import
  Domain/           # entities, value objects, state machines, domain events
  Application/      # use cases / command handlers
  Infrastructure/   # Eloquent models, repositories, adapters
  Http/             # controllers, requests, resources
  Console/          # commands
  Listeners/        # event consumers
  Database/         # migrations/, factories/
  Tests/
```

Classes are namespaced `Modules\<Module>\<Layer>\…` — `Modules\` is a PSR-4 root
mapped to `app/Modules/`.

---

## The five boundary rules

These are enforced by the build, not by review. A merge request that breaks one of
them fails CI.

1. **A module exposes a public API — `Modules/X/Contracts/` — interfaces and DTOs
   only.** Everything else is internal.
2. **A module never queries another module's tables.** Cross-module reads go through
   the contract or a read model.
3. **A module never writes another module's tables.** Cross-module writes go through
   the contract or a domain event.
4. **Eloquent models are internal.** They do not cross module boundaries — DTOs do.
5. **Modules depend on `Platform` and on other modules' `Contracts` namespace only.**

Two rules from the data conventions (TAD §7.1) are enforced alongside them:

6. **No database foreign key may cross a module boundary.** Cross-module references
   are logical ids; integrity is enforced in the owning module's application layer.
   A real FK across a boundary is exactly what blocks the service extraction rule 1
   exists to keep possible.
7. **Every table has exactly one writing module** — whichever module's
   `Database/migrations` creates it.

And one from the delivery rules (TAD §16.2):

8. **Migrations are expand/contract only**, reversible or explicitly flagged
   irreversible with a written rollback plan. A destructive change never ships in
   the same release as the code that stops using the column.

---

## Running the checks locally

Three checks, four CI jobs. Each one is a single command with no setup beyond
`composer install`, and CI runs **exactly these commands** — see `.gitlab-ci.yml`,
which contains no check logic of its own.

| Check | Command | Shorthand |
|---|---|---|
| Module dependency boundaries | `vendor/bin/deptrac analyse --config-file=deptrac.yaml` | `composer guard:boundaries` |
| No cross-module foreign key | `php tools/schema-guard.php` | `composer guard:schema` |
| Migration discipline | `php tools/migration-guard.php` | `composer guard:migrations` |
| All three | | `composer guard` |
| Test suite | `php artisan test` | `composer test` |

Every check exits `0` when clean and non-zero with a named violation when not.

### 1. Module dependency boundaries — `deptrac`

Configured in `deptrac.yaml`. `Platform` is one layer; every other module
contributes two — `<Module>.Contracts` (public) and `<Module>` (everything else).
The ruleset lets a module reach `Platform` and any module's `.Contracts`, and
nothing else.

A violation looks like this:

```
DependsOnDisallowedLayer
  Modules\Payment\Application\RecordPaymentDirectly must not depend on
  Modules\Merchant\Infrastructure\MerchantModel
  You are depending on token that is a part of a layer that you are not
  allowed to depend on. (Merchant)
```

The fix is never to widen `deptrac.yaml`. It is to add the method you need to
`Modules\Merchant\Contracts\…` and return a DTO.

### 2. No cross-module foreign key — `tools/schema-guard.php`

Resolves table ownership from the tree itself: whichever module's
`Database/migrations` contains the `Schema::create()` owns the table. Then it fails
any foreign key whose referenced table is owned by a different module.

```
Foreign key `merchant_id` crosses a module boundary.
    constraint : merchant_id
    on table   : payments  (owned by Payment)
    references : merchants (owned by Merchant)
```

The fix is to drop the constraint and keep `merchant_id` as a plain
`unsignedBigInteger`, enforcing integrity in the owning module's application layer.

Two smaller failures come from the same check:

- **A table created by two modules.** Ownership must be unambiguous before a foreign
  key across it can be judged.
- **A foreign key whose target the guard cannot resolve statically** — a
  `->constrained()` on an unconventional column name, or `foreignIdFor(Model::class)`
  (which a migration may not use anyway, since models do not cross boundaries).
  Name the table explicitly: `->constrained('merchants')`.

### 3. Migration discipline — `tools/migration-guard.php`

Three rules, all decidable from the tree alone:

**Reversible, or declared irreversible.** Write a `down()`. If the change genuinely
cannot be reversed, say so and say what an operator does instead:

```php
/**
 * @irreversible
 * @rollback Restore the payments table from the pre-deploy snapshot taken by the
 *           release runbook, then redeploy the previous application version.
 */
```

An annotation must begin its own line in the docblock — prose that merely mentions
`@irreversible` is documentation, not a declaration.

**Destructive steps ship alone.** A migration that drops or renames anything may not
also add anything. Expand and contract are separate changes by definition.

**A destructive step names the expand step it follows:**

```php
/**
 * @contract-of 2026_02_01_000000_add_reference_to_payments_table
 */
```

The named migration must already exist in the repository with a strictly earlier
timestamp. That is what makes "no destructive change in the same release as the code
that stops using the column" checkable without a diff: the drop cannot be authored
until the step it contracts is already in the tree.

The full sequence for removing a column, across separate releases, is:

> add nullable → backfill in batches → switch code → make non-null → drop old

---

## Proving the guards

A guard nobody has watched fail is not a guard. `tests/Architecture/BoundaryFixtureTest.php`
runs each check against committed fixture trees under
`tests/Architecture/fixtures/` — a clean case and a synthetic violation for each —
and asserts the exit code and the message. It also runs all three against this
repository.

```bash
php artisan test --filter=BoundaryFixtureTest
```

The fixtures are deliberately plausible: each violation is the shape of merge
request that reads like tidy work and passes human review.

You can run any guard against a fixture by hand, exactly as the test does:

```bash
php tools/schema-guard.php    --root=tests/Architecture/fixtures/schema/cross-module-fk
php tools/migration-guard.php --root=tests/Architecture/fixtures/migration/unreferenced-drop
```

The boundary fixture needs deptrac pointed at the fixture tree, which the test does
by rewriting only the `paths` block of the real `deptrac.yaml` — so what runs is the
production ruleset, not a copy of it.

---

## Adding a module

Don't, unless the architecture document changed. If it did:

1. Create `app/Modules/<Name>/` with the nine directories above.
2. In `deptrac.yaml`, add the two layers (`<Name>.Contracts` and `<Name>`).
3. Add the ruleset entry for both, and add `<Name>.Contracts` to **every** other
   module's ruleset and to `App`.
4. Run `composer guard`.

---

## CI

`.gitlab-ci.yml` runs four blocking jobs on every merge request: the three guards
and the test suite. The file may only invoke `tools/*.php` and `vendor/bin/deptrac`
— no check logic lives in the pipeline, so the gates stay portable and every one of
them is reproducible on a laptop with the commands in the table above.
