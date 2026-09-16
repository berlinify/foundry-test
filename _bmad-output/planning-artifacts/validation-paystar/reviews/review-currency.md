# Reviewer: technology currency / reality-check lens

**Lens (configured `finalize_reviewers[0]`):** verify every committed decision was web-researched
or reality-checked rather than asserted from training data.

**Verdict: FAILED.** Three of fifteen `[CONFIRMED]` decisions bind technology that is
end-of-life as of today (2026-09-16). Verified against the live web, not recalled.

## Findings

### [critical] D-03 commits to Laravel 11 — security support ended 2026-03-12 (§1.1, §3.1)
Laravel's policy is 18 months of bug fixes, 24 months of security fixes. Laravel 11 (Mar 2024)
passed both gates. Laravel 13 is current (13.30.x, Sept 2026); Laravel 12 is also supported.
This is a **greenfield** build starting now — the migration cost of choosing 12/13 instead is
approximately zero, and the cost of not doing so is beginning a 3–5-year-horizon product on an
unpatched framework. Also note §3.1's Laravel-over-Nest rationale (fewest infrastructure lines
for a one-engineer team) is sound and **survives unchanged** on Laravel 12/13 — only the
version pin is wrong, not the framework choice.
*Fix:* repin D-03 to Laravel 12 LTS-equivalent or 13, and re-verify the RLS/tenancy middleware
assumptions in §5.2 against that version before Wave 0.

### [critical] D-13 commits to Nuxt 3 — reached EOL 2026-07-31 (§1.1, §3.2)
Nuxt 3's EOL was extended from 2026-01-31 to 2026-07-31 and has now passed; it receives no bug
or security patches. Nuxt 4 is the current stable line (4.5.x). §3.2's load-bearing choices —
runtime tenant theming via CSS custom properties, Pinia, `@nuxtjs/i18n` RTL, SPA-with-selective-SSR
— all carry forward to Nuxt 4.
*Fix:* repin D-13 to Nuxt 4 before the Wave 0 "Nuxt shell with design system and tenant theming"
task, which is precisely the wrong thing to build twice.

### [high] D-14 targetSdk 32 cannot be published or updated on Google Play (§1.1, §3.3)
From 2026-08-31, new apps and updates must target API 36; existing apps must target API 35+ to
remain available to new users. targetSdk 32 is below both thresholds.
The decision also **conflates two separate settings**: `minSdk 27` is what delivers the stated
"baseline device Android 8.0 Oreo" constraint and is correct. `targetSdk` does not affect which
devices can run the app — it selects which platform behaviours apply. The document gives a
rationale for the floor and none for the ceiling.
*Caveat:* if this POS app is distributed exclusively by the §12.6 OTA channel and never through
Play, the Play policy does not bind. **The TAD never states the distribution channel**, so this
cannot be resolved from the document.
*Fix:* state the distribution channel explicitly; if Play is in scope at any horizon, raise
targetSdk and budget the behaviour-change testing. Keep minSdk 27.

### [medium] PHP 8.3 is in security-only maintenance (§1.1 D-03, §3.1)
PHP 8.3 left active support at the end of 2025; security-only until 2027-12-31. Usable, but for
a product with a declared 3–5 year horizon it means a forced upgrade inside the first horizon
year. PHP 8.4 (to 2028-12-31) or 8.5 (to 2029-12-31) costs nothing extra at greenfield.
*Fix:* pin 8.4 or 8.5 alongside the Laravel repin — one migration, not two.

### [low] Redis 7 pinned without noting the licensing split (§3.1)
Redis relicensed in 2024 and Valkey forked. For self-hosted in-cluster use this is unlikely to
bite, but the pin was made without the question being visible in the document.

## What this lens did NOT find
PostgreSQL 16 (supported to Nov 2028), Kafka, Kubernetes, OpenTelemetry, Prometheus/Grafana/Loki,
MinIO, Sentry, Hilt/Room/WorkManager/Retrofit, `deptrac`, ECharts — all current and appropriately
chosen. The infrastructure half of the stack is in good shape.

## Sources
- https://www.herodevs.com/blog-posts/php-end-of-life-dates-support-timeline-for-every-version-2026
- https://endoflife.date/laravel
- https://eosl.date/eol/product/laravel/
- https://developer.android.com/google/play/requirements/target-sdk
- https://support.google.com/googleplay/android-developer/answer/11926878
- https://www.herodevs.com/blog-posts/nuxt-3-reaches-end-of-life-on-july-31-2026-what-are-your-options
- https://nuxt.com/docs/4.x/community/roadmap
