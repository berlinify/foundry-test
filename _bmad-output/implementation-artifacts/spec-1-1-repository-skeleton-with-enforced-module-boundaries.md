---
title: 'Story 1.1 — Repository skeleton with enforced module boundaries'
type: 'chore'
created: '2026-09-16'
status: 'in-review'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'ba7307740814623c572363651260396bcf80b617'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The repository holds planning artifacts and no application code. Epic 1's twelve remaining stories, and every epic after it, are written to land inside a nineteen-module structure that does not exist yet. The architecture's own position is that without automated enforcement the modular monolith degrades into an ordinary monolith within two sprints — and boundary enforcement retrofitted onto existing code is a far harder, far more contested job than enforcement that predates the first module.

**Approach:** Scaffold a Laravel 13 application with the nineteen-module skeleton and its internal layering, then wire three merge-blocking checks into CI before any module has content: module dependency boundaries, no foreign key crossing a module boundary, and migration discipline.

## Boundaries & Constraints

**Always:** A module exposes only `Contracts/` — interfaces and DTOs. Modules depend on `Platform` and on other modules' `Contracts` namespace, nothing else. Enforcement is automated and fails the build; it is never a review convention. Use `deptrac/deptrac` — `qossmic/deptrac` is abandoned and must not be installed. Composer requires `php: ^8.4`, which admits the local 8.5.4. Every check must fail loudly on a synthetic violation, proven by a committed fixture.

**Never:** No business logic, no domain tables, no entities, and none of the ~60 MVP tables — those belong to the stories that need them. Do not implement tenancy, RLS, auth, the access-decision service or the outbox; those are Stories 1.2–1.10. Do not scaffold the Nuxt portal or the Android app (Stories 1.12, 1.13). Do not add a module beyond the nineteen named in the architecture.

## Decisions

- **Repository topology: backend-only.** Laravel sits at the repository root in the default layout. The Nuxt portal and Kotlin POS app get their own repositories; Stories 1.12 and 1.13 will begin by creating them. No commit in this repository spans backend and client.
- **CI platform: GitLab CI**, matching PayStar's assumed standard pipeline rather than this repository's GitHub remote. The accepted consequence is that the pipeline itself cannot be exercised here and ships unverified. Mitigation, which is binding: all three checks are standalone PHP scripts invoked identically by hand and by the pipeline, so the *checks* are locally provable even while the *pipeline* is not. No check logic may live inside `.gitlab-ci.yml` — it may only call the scripts.
- **Scope kept whole** despite exceeding the token guideline: the guards must predate the modules they guard.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Boundary check, clean | Module imports another module's `Contracts` interface | `deptrac` exits 0 | N/A |
| Boundary violation | Module imports another module's Eloquent model or `Domain` class | `deptrac` exits non-zero, naming source, target and rule | CI job fails, merge blocked |
| Platform dependency | Any module depends on `Platform` | Allowed, exits 0 | N/A |
| FK check, clean | Migration adds FK within one module | Schema check exits 0 | N/A |
| Cross-module FK | Migration adds FK from `Payment` table to `Merchant` table | Schema check exits non-zero, naming the constraint, table and referenced table | CI job fails, merge blocked |
| Migration discipline | Migration drops a column in the same change that stops using it | Check exits non-zero, naming the migration | CI job fails, merge blocked |
| Irreversible migration | Migration has no `down()` and no explicit irreversible marker | Check exits non-zero | CI job fails, merge blocked |

</frozen-after-approval>

## Code Map

Greenfield — nothing exists to reuse. Authoritative references for the implementer:

- `_bmad-output/implementation-artifacts/epic-1-context.md` -- compiled epic context; module list, layering, boundary rules, data conventions. Read first.
- `Paystar Market TAD.md` §4 -- the nineteen modules and their names, verbatim; the five boundary rules; per-module layering. Use as the naming source of truth.
- `Paystar Market TAD.md` §7.1 -- data conventions, including the no-cross-module-FK and one-writing-module-per-table rules the schema check enforces.
- `Paystar Market TAD.md` §16.2 -- expand/contract migration discipline the migration check enforces.
- `_bmad-output/planning-artifacts/epics.md` -- Story 1.1's acceptance criteria as written.
- Do not modify anything under `_bmad/` or `.claude/` — installed tooling.

Verified toolchain facts (checked against Packagist, not recalled): `deptrac/deptrac` latest 4.7.2; `qossmic/deptrac` abandoned; `laravel/framework` latest v13.32.0; local PHP 8.5.4, Composer 2.9.5, Node 24.19.0, no Docker.

## Tasks & Acceptance

**Execution:**
- [x] `composer.json` -- create the Laravel 13 application, pin `php: ^8.4`, add `deptrac/deptrac` to `require-dev`, register a `Modules\\` PSR-4 autoload root -- the application does not exist yet
- [x] `app/Modules/<Module>/` -- create all nineteen modules, each with `Contracts/`, `Domain/`, `Application/`, `Infrastructure/`, `Http/`, `Console/`, `Listeners/`, `Database/`, `Tests/`, each directory kept by a `.gitkeep` -- the structure every later story lands inside
- [x] `deptrac.yaml` -- one layer per module plus `Platform`; ruleset allowing each module to reach only `Platform` and other modules' `Contracts` -- the boundary rule, enforced
- [x] `tools/schema-guard.php` -- fail when a migration declares a foreign key whose referenced table belongs to another module; resolve table ownership from each module's `Database/migrations` -- cross-module FKs silently block the service extraction the architecture depends on
- [x] `tools/migration-guard.php` -- fail on a migration that is destructive in the same change that stops using a column, or that has neither a `down()` nor an explicit irreversible marker with a written rollback note -- expand/contract discipline
- [x] `tests/Architecture/BoundaryFixtureTest.php` -- committed fixtures proving each of the three checks fails on a synthetic violation and passes on a clean case -- a guard nobody has seen fail is not a guard
- [x] `.gitlab-ci.yml` -- run the three guard scripts plus the test suite on every merge request as blocking jobs; the file may only invoke `tools/*.php` and `vendor/bin/deptrac`, never contain check logic -- keeps the gates portable and locally provable
- [x] `README.md` -- module map, the five boundary rules, and how to run each check locally -- the rules must be discoverable without reading the architecture document

**Acceptance Criteria:**
- Given a clean checkout, when `composer install` then all three checks run, then every check exits 0 and the suite passes.
- Given the nineteen modules, when the structure is compared to the architecture's module list, then names and layering match exactly, with no extra or missing module.
- Given a pull request containing any one of the three synthetic violations, when CI runs, then the corresponding job fails and the merge is blocked.
- Given a developer with only the README, when they run the documented commands locally, then they reproduce CI's results without further instruction.
- Given `.gitlab-ci.yml` cannot be executed from this remote, when the guards are run by hand, then each produces the same pass and fail results the pipeline would, so the checks are proven even though the pipeline is not.

## Implementation Notes

Installed: `laravel/laravel` ^13 → `laravel/framework` v13.32.0, `deptrac/deptrac` 4.7.2, on PHP 8.5.4. No platform conflict with `php: ^8.4`.

**deptrac layering.** The spec's "one layer per module plus `Platform`" cannot express rule 1 on its own: if a module is a single layer, allowing `Payment → Merchant` allows `Payment → Merchant\Infrastructure` too. So `Platform` is one layer (the whole module is public) and every other module contributes two — `<Module>.Contracts` and `<Module>` (everything else) — giving 37 module layers plus an `App` layer for the Laravel host, which may reach contracts but never internals, and which no module may reach back into. 38 layers, `paths: ./app`.

**"Destructive in the same change that stops using a column" made checkable.** A static guard cannot see a diff or a release boundary, so `tools/migration-guard.php` enforces the property by construction instead: a destructive migration (a) may contain no additive operation, and (b) must carry `@contract-of <migration>` naming a migration already in the tree with a strictly earlier timestamp. The drop therefore cannot be authored until its expand step has shipped. Reversibility is the third rule: a real `down()` body, or `@irreversible` plus an `@rollback` note of ≥20 characters. Annotations must begin their own docblock line, so prose that merely mentions `@irreversible` cannot talk a migration past the guard.

**Laravel's default `users` table and `App\Models\User` were deleted.** `users` is an Identity-module table with a different shape (TAD §7.2, Cluster 2); leaving the framework's version at the repository root would have created an MVP table this story is forbidden to create, owned by no module, that Story 1.4/1.5 could then never claim. `.env.example` moves to `SESSION_DRIVER=file` accordingly, and `config/auth.php` carries a note pointing at Story 1.4. Laravel's `cache` and `jobs` migrations are kept — framework infrastructure, resolved by the schema guard as the `@framework` pseudo-module, which a module may not point a foreign key at either.

**Additions not named in the spec, each load-bearing for an acceptance criterion:**
- `tools/lib/migration-source.php` — tokeniser shared by both guards (migration classes are anonymous, so reflection would mean executing them). Still no Composer autoload and no framework boot: `php tools/<guard>.php` is the whole invocation.
- Both guards accept `--root=<dir>`, which is how the fixture trees are scanned through the exact production code path.
- `APP_KEY` in `phpunit.xml` — a throwaway committed test key, so `php artisan test` passes on a clean checkout with no `.env`, as AC 1 requires.
- `pint.json` excluding `tests/Architecture/fixtures` — fixtures are guard input, not code to style. The rest of the tree is Pint-clean.
- `exclude-from-classmap` for the fixtures, so the optimized autoloader does not warn about their deliberately non-PSR-4 namespaces.

**Not verifiable here, by the spec's own accepted consequence:** `.gitlab-ci.yml` ships unexercised — this remote is GitHub. Its mitigation holds: all four jobs are one-line invocations of `vendor/bin/deptrac` or `tools/*.php`, and every one of them was run by hand and is asserted by `BoundaryFixtureTest`.

**Fix applied during verification (not by the implementation agent).** The scaffold was missing
Laravel's per-directory `.gitignore` placeholders under `storage/` and `bootstrap/cache/`. Two
consequences: five generated artifacts (`bootstrap/cache/packages.php`, `services.php` and three
compiled Blade views) were staged for commit, and nine runtime directories contained no tracked
file, so they would not have survived a fresh clone — including `bootstrap/cache`, which
`package:discover` writes to during `composer install`. Added the eleven standard placeholders and
removed the generated files from the index. Acceptance criterion 1 was then re-verified from a
genuine tracked-files-only checkout with no `vendor/` and no `.env`: all three guards exit 0 and
the suite is 12/12.

## Spec Change Log

## Review Triage Log

## Verification

**Commands:**
- `composer install` -- expected: resolves on PHP 8.5.4 with no platform conflict
- `vendor/bin/deptrac analyse --config-file=deptrac.yaml` -- expected: exit 0, zero violations
- `php tools/schema-guard.php` -- expected: exit 0 on the clean tree
- `php tools/migration-guard.php` -- expected: exit 0 on the clean tree
- `php artisan test` -- expected: green, including the three fixture tests
- `php artisan test --filter=BoundaryFixtureTest` -- expected: proves each guard fails on its synthetic violation
