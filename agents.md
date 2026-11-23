# Agent Guidelines & Project Instructions — LoanBroker Platform

> **READ THIS FILE COMPLETELY BEFORE MODIFYING THE PROJECT.**
>
> Planning is done. Phase 0 (audit) and Phase 1 (single-database tenancy) are done. The app lives in the project root; `PLANNING.md` says what to build. Read §1 before writing any code.

---

# 1. Current Project State (read this first — it will go stale, keep it updated)

- **The app lives in the project root (`multitenant-loan/`)** (confirmed by the user). The starter kit's files were copied here on 2026-10-05; all build work happens here.
- `laravel-multi-tenant-saas-starter-main/` is now only a pristine copy of the starter. Do not edit it.
- Phase 0 is done: read `docs/architecture/PHASE_0_AUDIT.md`. It corrects the plan in places (string tenant ids, integer user ids, no roles tables) and lists environment blockers.
- Phase 1 is done. One PostgreSQL database: `DatabaseTenancyBootstrapper` is off, creating a tenant inserts a tenant and a domain and does not create a database, and `BelongsToTenant` fails closed when no tenant is resolved (only rows with a null `tenant_id` are visible). Auth, password reset, suspension, and queued jobs are tenant-scoped. Isolation is proven by `tests/Feature/Tenancy/TenantIsolationTest.php`. Run the suite inside Docker: `docker compose run --rm app php artisan test`.
- The sample projects feature is still present. It is the tenant-scoped stand-in the isolation tests use, and it goes away when the loan tables replace it.
- `tenants.is_active` is still the starter's boolean. The `status` enum and the six product roles are Phase 3.
- Next is Phase 2, the Vue migration (§1.1). Do not start it until Phase 1 has been reviewed.
- Other confirmed decisions: PostgreSQL 17 on Neon, user email unique per tenant, Ghana defaults (`GH` / `GHS` / `Africa/Accra`). Details and consequences are in `PLANNING.md` §1.
- `lamas-php-main/` will be removed later, so capture anything worth keeping from it in writing during Phase 0.
- Two reference repositories are present at the project root:
  - `laravel-multi-tenant-saas-starter-main/` — the original starter kit the app was copied from.
  - `lamas-php-main/` ("LAMaS") — a **read-only domain reference**. Never edit it, never copy its files wholesale. Mine it only for domain concepts (Customer, LoanApplication, Broker, Promoter, CreditRisk models; polymorphic Address/Phone patterns).
- Two AI-generated planning documents sit at the project root. **They are suggestions, not binding dual specs** — read both in full, then reconcile them with judgment rather than treating every difference as a blocking conflict:
  - `deepseek_markdown_20261004_cb0be2.md` — concrete, implementation-level: a lean 14-table schema, the `BelongsToTenant` trait, service class signatures, controller/route lists, dashboard specs, test list, 7-phase build order.
  - `cahtgpt_markdown_guide.md` — a more elaborate, "scale-up" shape of the same product: a richer ~21-table schema (separate lender-match/submission/quote stages, separate markup-rules/pricing-override tables, separate commission-rules table, normalized borrower sub-tables), required Phase 0 audit docs, defense-in-depth tenant isolation layers, RLS considerations, a 21-phase build order, and frontend/UX guidance.
  - They fully agree on the core architecture: single PostgreSQL database, `tenant_id` row-level scoping — **not** database-per-tenant (confirmed by the user — see §2). Where they differ in ambition level (lean vs. enterprise-shape), §5 below resolves it: build the lean (deepseek) schema first, with a handful of cheap enrichments pulled from chatgpt's version; treat chatgpt's fuller pipeline as a documented later phase, not dead content.
  - Do not delete these two planning files; they remain as background.
- **`PLANNING.md` is the consolidated plan** — the single source for what is being built (decisions, schema with columns, services, routes, dashboards, frontend, tests, phases). Read it instead of the two drafts. If this file and `PLANNING.md` disagree, fix whichever is wrong rather than picking silently.

## 1.1 Frontend stack: Vue (decided)

Both planning docs specify **Vue 3 + Inertia.js + TypeScript + Tailwind + Vite**. The user has confirmed Vue. The actual starter kit (`laravel-multi-tenant-saas-starter-main`) currently ships **React 18 + Inertia.js + Tailwind + Vite, plain JavaScript** — see its `package.json` (`@inertiajs/react`, `react`, `@vitejs/plugin-react`) and `composer.json` (`inertiajs/inertia-laravel`, no Vue packages). This must be converted; it has not been done yet.

**Do not maintain React and Vue side by side long-term.** Follow this migration order (from the chatgpt guide, adopted as-is — it's sound):
1. Inspect all existing React pages to know what must be preserved.
2. Install Vue 3 + `@inertiajs/vue3` + TypeScript; add a Vue/Inertia bootstrap (`resources/js/app.ts`) alongside the existing one.
3. Port infrastructure-critical pages first: auth, then central admin, then the tenant shell/layout.
4. Port remaining tenant UI.
5. Verify feature parity against the React version before removing anything.
6. Remove React, `@inertiajs/react`, and `@vitejs/plugin-react` only after Vue has full parity.

See §8.1 for the target `resources/js/` structure and frontend UX guidance.

---

# 2. Tech Stack (as it exists today, per the starter kit's actual lockfiles)

- **Backend:** Laravel 12, PHP 8.4+
- **Database:** PostgreSQL 17 on Neon, **single database for the whole platform** (confirmed by the user). Not database-per-tenant. Pooled connection for the app, direct connection for migrations.
- **Multi-tenancy package:** `stancl/tenancy` ^3.9 — currently configured for **database-per-tenant**. Must be converted to single-database, `tenant_id`-scoped mode (remove `DatabaseTenancyBootstrapper` from `config/tenancy.php`; keep domain/subdomain identification middleware).
- **Auth:** Laravel Sanctum (API), Laravel Breeze scaffolding present.
- **Frontend (target):** Vue 3 + `@inertiajs/vue3` + TypeScript + Tailwind CSS + Vite (confirmed by the user). **Frontend (current, to be migrated away from):** React 18 + Inertia.js v2 — see §1.1 for the migration order.
- **Tenant resolution:** subdomain-based (`acme.localhost`, `swift.localhost` style).
- **Money/rate data types:** `NUMERIC`/`decimal` only — money as `decimal(15,2)`, rates as `decimal(6,3)`. Never `FLOAT`/`DOUBLE` for anything financial.

LAMaS (`lamas-php-main`), for contrast — **do not adopt these, reference only**: Laravel 11, Jetstream, Livewire 3, AdminLTE, Spatie Laravel Permission, yajra DataTables.

---

# 3. Product Overview

A SaaS platform for loan agencies and independent loan agents. Each agency or independent agent is a **tenant**. The agency/agent is the sole intermediary between **lenders** ("bank rollers") and **borrowers** — a borrower never deals with a lender directly.

```text
LENDER (bank roller)  ◄──►  AGENCY / AGENT  ◄──►  BORROWER
```

Business flow:

```text
Borrower → Agency/Agent → Loan Application → Multiple Lenders → Loan Products
→ Eligibility Matching → Lender Quote → Agency Markup → Customer Offer
→ Customer Selection → Lender Decision → Loan → Disbursement → Loan Ledger
→ Agency/Agent Commission
```

**Rate integrity rule (critical, never violate):** the lender's base rate, the agency's markup, and the resulting customer rate must always be stored and preserved independently. Never overwrite the lender's rate with the customer rate.

```text
lender_base_rate   e.g. 12.00%
agency_markup      e.g.  3.00%
customer_rate      e.g. 15.00%   (derived, never collapses the other two)
```

**Visibility rule (critical):** the borrower never sees `lender_base_rate`, markup fields, or `total_markup_earned`. The lender sees only what the agency explicitly allows (per-lender `LenderVisibilitySetting`), not the agency's full internal data. Money flows Lender → Agency → Borrower, then Borrower → Agency → Lender; the markup spread is the agency's revenue.

## 3.1 Roles

| Role | Scope | Purpose |
|---|---|---|
| Super Admin | Platform (central) | Creates/manages tenants, platform-wide dashboard |
| Agency Admin | Tenant | Sets markup caps, lender visibility, manages agents, agency dashboard |
| Agency Manager | Tenant | Oversees agents and portfolio, team dashboard |
| Agent | Tenant | Onboards lenders, registers borrowers, sets markup, personal dashboard |
| Lender User | Tenant | Defines products, approves/rejects applications, sees only what visibility settings allow |
| Borrower | Tenant | Views offers, accepts/rejects, repays, personal dashboard |

This is the V1 role set. The chatgpt guide suggests finer-grained roles — agency-side `AGENCY_OWNER`, `COMPLIANCE`, `AUDITOR`; lender-side `LENDER_ADMIN`, `LOAN_OFFICER`, `UNDERWRITER`; platform-side `PLATFORM_ADMIN`, `PLATFORM_SUPPORT`, `PLATFORM_AUDITOR`. Add one only when a concrete feature needs it. Use one authorization system: review the starter's existing role implementation first, and introduce Spatie Laravel Permission only if it fits cleanly — never run two side by side.

A tenant is either an **agency** or an **independent agent**; an independent agent's owner also has an Agent profile. Don't create a separate Agency model — the tenant is the agency.

**User email is unique per tenant** (`UNIQUE (tenant_id, email)`, plus a partial unique index for tenant-less super admins). Every auth lookup — login, registration, password reset — must be scoped to the current tenant.

## 3.2 Out of scope for the initial implementation

Do **not** build yet, even if it seems like a natural next step:

AI chatbot/underwriting/lender-recommendation, OCR, automatic ID extraction, automatic bank-statement analysis, Open Banking, credit-bureau automation, automated repayments, direct debit, payment gateway integration, collections/debt-recovery automation, predictive credit scoring, advanced analytics, complex accounting integrations, lender API automation.

The first objective is the correct brokerage domain model and tenant isolation — not integrations.

---

# 4. Tenant Isolation (the most important non-negotiable requirement)

Single PostgreSQL database. **No database-per-tenant.** Every tenant-owned table carries a `tenant_id` UUID FK to `tenants.id`, `cascadeOnDelete`, indexed. Compound unique constraints must include `tenant_id` (e.g. `UNIQUE(tenant_id, slug)`, never bare `slug`).

Required layers of protection (do not rely on only one):

1. **Tenant context** — resolved before any business logic runs (domain/subdomain → `tenant()`).
2. **`BelongsToTenant` trait** on every business model (`app/Models/Concerns/BelongsToTenant.php`) — auto-populates `tenant_id` on create, adds a global scope filtering every query to the current tenant. Every business model uses `HasUuids, BelongsToTenant` — no exceptions.
3. **Authorization (policies)** — must confirm both the user's permission *and* that the resource's `tenant_id` matches the user's tenant.
4. **Database constraints** — FKs and tenant-aware unique indexes, not just Eloquent/Laravel validation.
5. **Automated tests** — cross-tenant access must be covered (see §7).

Platform/system processes that legitimately need cross-tenant access (queues, super-admin aggregate dashboards, CLI, migrations/seeders) must use an explicit, documented `withoutGlobalScope('tenant')` call — never a blanket scope removal, and never silently.

Avoid raw `DB::table()` queries on business tables — use Eloquent so the tenant scope actually applies. PostgreSQL Row-Level Security may be added later as defense-in-depth per the chatgpt guide, but only after application-level scoping works and connection pooling/tenant-context implications are understood — application-level scoping remains mandatory even if RLS is added later.

---

# 5. Domain Model

The two planning docs model the same product at two different ambition levels: deepseek's is a lean, directly-buildable 14-table schema; chatgpt's is a richer ~21-table "scale-up" shape with separate lender-match/submission/quote stages, separate markup-rules/pricing-override tables, a separate commission-rules table, and normalized borrower sub-tables. Consistent with the project's own anti-overengineering principle (§9), **build the lean schema first** (§5.1), with a few cheap enrichments pulled from the richer version (§5.2 marks them). Treat the fuller pipeline (§5.3) as a real, intentional later phase — not something to build speculatively now, and not something to discard either.

## 5.1 V1 schema (build this)

**Central tables** (no `tenant_id` — shared infrastructure): `tenants`, `domains`, `users` (extended with nullable `tenant_id`, `role`, `agent_id`, `borrower_id`, `lender_id`), `platform_audit_logs` (see §5.2). The starter has no roles or permissions tables; V1 uses the `role` column, a role middleware and policies.

**Business tables** (all have `tenant_id`, all use `BelongsToTenant`; see `PLANNING.md` §6 for exact columns):

```text
lenders
lender_visibility_settings
loan_products
agents
borrowers
applications
application_documents
application_status_history   ← added, see §5.2
offers                       ← core model: snapshots guardrails + computed rate/payment fields
loans
loan_ledger
repayment_schedule
markup_limits
commissions
audit_logs
```

Money fields are `decimal(15,2)`. Rate fields are `decimal(6,3)`. `audit_logs` uses a `bigserial` PK (everything else uses UUID PKs via `HasUuids`).

## 5.2 Cheap enrichments pulled in from the richer (chatgpt) version

These are worth adding to the lean V1 build because they're low-cost and close a real gap:

- **`application_status_history`** — one row per status transition (`application_id`, `from_status`, `to_status`, `reason`, `changed_by`, `created_at`). Cheap insert on every transition; gives a real audit trail for a workflow that currently only has deepseek's `applications.status` enum with no history.
- **Offer versioning instead of silent overwrite** — deepseek's `OfferController@update` already restricts edits to `status = 'draft'` and logs to `audit_logs`, which covers most of chatgpt's "never overwrite historical offers" concern. Extend it one step further: once an offer has been sent (`status != 'draft'`), an edit must create a **new** offer row (incrementing a `version` column) and flip the old row to `status = 'superseded'` rather than mutating it in place. Add a `version` integer column (default 1) to `offers` for this.
- **`platform_audit_logs`** (central table, separate from tenant-scoped `audit_logs`) — tenant create/suspend/reactivate, domain changes, impersonation start/end, platform setting changes. Keeping platform events out of the tenant-scoped audit table is a cheap, clean separation.
- **Soft deletes, scoped narrowly** — add `deleted_at` to reference entities only: `borrowers`, `lenders`, `loan_products`, `agents`. **Never** soft- or hard-delete financial/audit records — `loan_ledger`, `audit_logs`, `offers`, `commissions`, `loans` are immutable; correct them with reversal entries, not deletes or edits.
- **Concurrency guards on money-moving actions** — use `lockForUpdate()` (or equivalent) plus DB uniqueness/idempotency checks around offer acceptance, offer→loan creation, disbursement recording, and commission creation, to prevent double-accept, double loan creation, duplicate disbursement, or duplicate commission rows under concurrent requests.

## 5.3 Later phase: the fuller lender pipeline (build only when V1 proves insufficient)

chatgpt's richer shape splits what deepseek's single `offers` table does into three auditable stages, and adds process tables deepseek omits. Don't build these speculatively — but don't lose the design either:

```text
lender_matches        — persisted eligibility result (ELIGIBLE/INELIGIBLE/REVIEW_REQUIRED) per
                         application × lender product, with a rule_snapshot JSONB. Buys: an audit
                         trail of *why* a product was or wasn't offered, independent of whether a
                         submission ever happened.
lender_submissions     — the lender-facing review lifecycle (DRAFT → SUBMITTED → UNDER_REVIEW →
                         MORE_INFORMATION_REQUIRED → APPROVED/REJECTED/COUNTERED/WITHDRAWN/EXPIRED),
                         separate from the commercial terms. Buys: a real "request more info" /
                         "counteroffer" workflow instead of a single approve/reject action.
lender_quotes          — the lender's commercial terms as their own versioned, immutable record,
                         separate from the agency's markup. Buys: counteroffers become a new quote
                         version rather than a mutation, independent of the customer-facing offer.
markup_rules           — declarative default/min/max markup by lender or product, distinct from
                         markup_limits' hard ceiling. Buys: a configurable default instead of every
                         agent picking a markup from scratch each time.
pricing_overrides      — a formal approval workflow when an agent requests a markup above the
                         default rule. Buys: supervisor sign-off on policy exceptions, auditable.
loan_disbursements     — a dedicated verify-then-ledger step (recorded_by / verified_by / verified_at)
                         before a disbursement ledger entry is created. Buys: a maker-checker control
                         on money leaving the platform.
commission_rules       — per-lender/per-product commission configuration, replacing the flat
                         per-agent `commission_share_pct` on the Agent model. Buys: commission
                         structures that vary by lender or product, not just by agent.
loan_product_rules     — a small declarative eligibility-rule table (field/operator/value) instead
                         of hard-coded eligibility checks. Buys: agency-configurable eligibility
                         without a deploy, without building a general rule engine.
borrower_addresses / borrower_employment / borrower_income
                       — normalize what V1 keeps as flat columns on `borrowers`. Buys: multiple
                         addresses/employers per borrower over time, if that turns out to matter.
```

Move a piece from here into the real schema only when a concrete need justifies it — e.g. "we have real lenders asking for countered terms as a named state" justifies `lender_submissions`, not "it might be nice."

---

# 6. Critical Implementation Rules

These are non-obvious and easy to violate by accident. Treat them as hard constraints:

1. **Markup validation only lives in `MarkupService`.** Never validate markup limits in the frontend or in FormRequests.
2. **Offers snapshot their guardrails at creation time.** A later admin change to `markup_limits` must never retroactively affect an already-created offer.
3. **Log every markup change** to `audit_logs` (`entity_type = 'offer'`), and **every lender-visibility change** (`entity_type = 'lender_visibility'`), including old/new values and who changed it.
4. **Never return a raw `Offer` (or other sensitive) model from a controller.** Always go through a role-specific API Resource (`BorrowerOfferResource` strips markup/base-rate fields entirely; `LenderApplicationResource` must consult `LenderVisibilityService` field-by-field — no shortcuts).
5. **Wrap money/state-changing operations in DB transactions**: offer→loan provisioning, marking a loan disbursed, recording a repayment.
6. **Unique constraints must include `tenant_id`.** Never rely on a bare unique index for anything tenant-owned.
7. **`withoutGlobalScope('tenant')` is an exception, not a pattern** — acceptable in tests and documented super-admin aggregate queries only; comment why whenever it's used.
8. Dashboard services live under `app/Services/Dashboards/`; controllers stay thin and just call the service.
9. **Laravel is authoritative for every financial outcome.** `customer_rate = lender_base_rate + agency_markup` is computed and persisted server-side. Vue may preview a figure but never produces a persisted one, and no pricing math lives in controllers.
10. **Historical commercial values are snapshots.** An existing offer or loan must never change because a loan product's rate or a markup limit changed later. Never reconstruct a loan's terms from the current `LoanProduct`.
11. **Sent offers are superseded, not edited** (§5.2): new row, incremented `version`, old row marked `superseded`.
12. **Financial and audit records are never deleted or edited in place** — correct with reversal entries. Soft deletes are for reference entities only (§5.2).
13. **Lock money-moving actions** (§5.2) so concurrent requests cannot double-accept an offer, create two loans, or duplicate a disbursement or commission.
14. **Application status changes go through one workflow service** that rejects invalid transitions (e.g. `draft → disbursed`) and writes `application_status_history`. No arbitrary status assignment elsewhere.
15. **Borrower documents use private storage**; every download passes an authorization check that includes tenant ownership.
16. **Lender users are scoped twice**: `tenant_id = current tenant` AND `lender_id = their lender`. A lender user never sees another lender's applications, terms, or notes, and a lender's internal notes never become visible to agency users or borrowers automatically.
17. **Hiding a control in Vue (`v-if`) is UX, not security.** Every permission is enforced in Laravel.
18. **JSONB is for genuinely flexible data** (audit snapshots, ledger metadata, eligibility detail) — not a substitute for relational columns.
19. **Lead tenant-owned indexes with `tenant_id`** (e.g. `(tenant_id, status)`, `(tenant_id, borrower_id)`), based on real query patterns. A plain FK does not prove two rows share a tenant; enforce tenant consistency in services and tests, and use composite `(tenant_id, id)` foreign keys on high-risk relations where maintainable.

---

# 7. Testing Expectations

At minimum, cover:

- Tenant isolation: create data as Tenant A, confirm Tenant B's query returns empty, confirm `withoutGlobalScope('tenant')` proves the row still exists.
- Markup service: percentage/flat-fee computed correctly; above-max, disallowed-type, and negative inputs all throw.
- Privacy: borrower-facing JSON never contains markup/base-rate fields; lender-facing JSON strictly follows `LenderVisibilitySetting` toggles field-by-field.
- Ledger integrity: a repayment creates exactly 3 ledger entries (borrower_payment, lender_payout, agency_revenue); disbursement creates exactly 1.
- Dashboard access control: every role reaches its own dashboard; cross-role access returns 403.
- Cross-tenant access by guessed UUID: Tenant A cannot read or update Tenant B's borrowers, lenders, applications, offers, loans, ledger, commissions, or audit rows.
- Relationship leaks: `$application->borrower`, `$lender->loanProducts`, `$loan->offer`, `$commission->agent` never return a row from another tenant.
- Lender scoping: Lender A's user cannot see Lender B's applications or terms, and cannot see agency margin unless visibility settings allow it.
- Authority: an agent cannot modify a lender's base rate or exceed markup limits; a tenant admin cannot reach platform administration; a suspended tenant cannot access the tenant application.
- Historical pricing: create an offer at product rate 10% + 3% markup, change the product rate to 12%, confirm the existing offer (and any loan from it) still reads 10 / 3 / 13.
- Workflow: invalid transitions (e.g. `draft → disbursed`) fail; the valid path and the terminal states (rejected, withdrawn, cancelled, expired) are covered.
- Concurrency/idempotency: accepting the same offer twice, or creating a loan from it twice, yields one loan and one commission.

A phase is not finished while its tests fail — do not move to the next one.

Run `php artisan test` (or the project's equivalent) before declaring any backend change complete. Do not claim success without running it.

---

# 8. Suggested Build Order (Phases)

Consolidated from both planning docs (deepseek's 7 phases, with chatgpt's audit, Vue-migration and hardening phases folded in):

- **Phase 0 — Audit.** No behavior changes. Inspect both repos (composer files, migrations, models, middleware, routes, auth, tenant resolution, queues, storage, frontend, tests) and write down what is actually there: the starter's current architecture, the LAMaS concepts worth transplanting, and the exact file-change plan. Keep the write-up proportionate — a few grounded docs under `docs/architecture/`, not thirteen speculative ones.
- **Phase 1 — Single-database tenancy.** Convert `stancl/tenancy` to single-DB mode (`config/tenancy.php`, `BelongsToTenant` trait); stabilize tenant creation, domain routing, tenant context, tenant auth, tenant-aware queues/cache/storage, and tenant suspension. Add cross-tenant security tests. **Do not proceed until isolation is proven.**
- **Phase 2 — Vue migration.** Port the starter from React to Vue 3 + TS in the order given in §1.1; remove React only after parity.
- **Phase 3 — Central schema + roles.** `tenants`/`users` extensions (including tenant type: agency vs. independent agent), roles and permissions, `platform_audit_logs`.
- **Phase 4 — Business migrations.** The §5.1 tables, all in `database/migrations/` (not `database/migrations/tenant/`).
- **Phase 5 — Models.** One per business table, each using `HasUuids, BelongsToTenant`.
- **Phase 6 — Services.** `MarkupService`, `LoanProvisioningService`, `LoanLedgerService`, `LenderMatchService`, `LenderVisibilityService`, `AuditService`, plus the application workflow service (§6 rule 14).
- **Phase 7 — Controllers, API Resources, routes, and the Vue pages for them.**
- **Phase 8 — Dashboards** (platform, agency admin, agency manager, agent, lender, borrower).
- **Phase 9 — Hardening and release readiness.** Review tenant escape, IDOR, document access, mass assignment, rate limiting, and queue/cache/storage/session tenancy; check indexes and N+1s with `EXPLAIN ANALYZE`; verify a fresh install, migrations, seeders, and the production build.

Within phases 4–7, build in domain order: agents → borrowers → lenders and lender users → loan products → applications → matching → offers and pricing → loans → ledger → commissions.

Pause after each phase and show what was created/modified and the command output, rather than running all phases unattended — this is a from-scratch domain build on top of someone else's starter kit, and early mistakes (wrong tenancy mode, missing `tenant_id` somewhere) are expensive to unwind later.

## 8.1 Frontend structure and UX

Target layout under `resources/js/` (use `<script setup lang="ts">`):

```text
resources/js/
├── app.ts
├── bootstrap.ts
├── Components/      Forms/ Tables/ Modals/ Status/ Money/ Loans/
├── Layouts/         PlatformLayout.vue  TenantLayout.vue  AuthenticatedLayout.vue  GuestLayout.vue
├── Pages/
│   ├── Platform/    Dashboard.vue  Tenants/  Domains/  Users/  Audit/
│   └── Tenant/      Dashboard.vue  Agents/  Borrowers/  Lenders/  LoanProducts/  Applications/
│                    Offers/  Loans/  LoanLedger/  MarkupLimits/  Commissions/  Audit/  Settings/
├── Composables/     usePermissions.ts  useTenant.ts  useCurrency.ts  useApplicationStatus.ts
└── Types/
```

- **The application detail screen is the main workspace**: summary, borrower, requested loan, documents, eligible products, offers, selected offer, loan, and activity/audit on one screen. Don't force users through many separate pages to process one application.
- **Offer comparison** shows lender, product, principal, term, customer rate, repayment amount, fees, total repayable, expiry, and action. Authorized agency users additionally see lender base rate, agency markup, and margin. Borrowers never see those; lender users never see competitor information.
- **PWA-compatible**: the chatgpt guide asks for the app to be installable as a PWA. Keep this in mind when setting up the Vue build; it is not a V1 blocker.

## 8.2 Demo data and the MVP acceptance walk-through

Seed a realistic tenant (e.g. "Prime Loan Agency": an agency admin, two agents, three lenders with five products between them, several borrowers and applications) **and a second, unrelated tenant** so isolation can be demonstrated, not just asserted.

The MVP is done when this works end to end: platform admin creates a tenant → agency admin logs in, creates an agent, lenders, lender users, products, and markup limits → agent creates a borrower, uploads documents, and creates an application → eligible products are identified → offers are created with markup within limits → lender users see only their own applications and approve or reject → agent compares offers, the customer selects one → the application transitions correctly → a loan is created with snapshotted terms → disbursement is recorded and reaches the ledger → commission is calculated with agency and agent shares → audit history shows every critical action → the second tenant can see none of it.

---

# 9. Core Engineering Principles

## 9.1 Fix the Root Cause — Never Build Around a Bug

When something is broken, investigate and fix the actual problem. Do not build a second implementation to bypass a broken one, add a parallel component/endpoint/helper, or add condition after condition to hide a defect.

```text
Problem → Reproduce → Trace existing implementation → Find root cause → Fix root cause → Verify affected flows
```

not:

```text
Problem → Workaround → Still broken → Another workaround → Technical debt
```

## 9.2 Existing Code First

Before writing new code, check whether the starter kit or LAMaS already has an equivalent component, service, model trait, validation rule, or pattern. Priority order: reuse → extend → refactor → write new. New code is the last resort.

## 9.3 Do Not Duplicate Existing Functionality

Avoid naming patterns like `LenderServiceV2`, `OfferControllerNew`, `ApplicationFixed` — these usually mean the existing architecture wasn't understood. Extend or fix the original instead.

## 9.4 Read Before You Write

Before any non-trivial change: read this file, read the two planning docs for the relevant section, inspect the relevant directory and the files directly involved, search for related functionality elsewhere, trace current behavior, then decide the smallest safe change.

## 9.5 Smallest Correct Change

A task affecting one feature should not cascade into changes across unrelated files. Before touching a file, confirm it actually needs to change for the task at hand.

## 9.6 Preserve Working Code

Don't rewrite working starter-kit infrastructure (auth, tenancy shell, admin panel, queues, caching, storage, notifications) just because a cleaner pattern occurs to you mid-task. Refactor only when it fixes a demonstrated problem, removes real duplication, or is required by the task.

## 9.7 Follow Existing Architecture

Keep the starter's backend conventions (namespaces, service-layer pattern, central vs. tenant route files) and the §8.1 frontend layout. Don't introduce a second state-management or styling system for one feature.

## 9.8 Never Mask Errors

No empty `catch {}` blocks, no suppressing console errors just to look functional. Fix, handle intentionally, log, or surface to the user — never silently swallow a failure, especially around money/ledger operations.

## 9.9 Avoid Patch-on-Patch Development

Before adding another conditional, override, timeout, or watcher, ask why the current implementation needs it. One justified exception is fine; five usually means the underlying implementation needs correcting.

## 9.10 Clear Component/Service Ownership

Don't duplicate business logic across controllers/services. When multiple screens or roles share real behavior, extract a shared service; when only a small piece differs, use parameters/role checks rather than a second nearly-identical implementation.

## 9.11 No New Dependency Without Need

Before installing a package, confirm Laravel/PHP/JS/the starter kit doesn't already solve it, and that the bundle-size/maintenance cost is justified. Never swap out `stancl/tenancy`, Inertia, or the chosen frontend framework on a personal preference.

## 9.12 Protect Public Contracts

Changing API response shapes, route names, event names, or the `BorrowerOfferResource`/`LenderApplicationResource` field sets affects real consumers (the frontend, other roles' dashboards). Search for consumers before changing a shared contract, and update them all.

## 9.13 Do Not Guess About the Repository

Never assume something doesn't exist, is unused, or is safe to delete without searching the actual repos first. The repository is the source of truth, not memory of a similar project.

## 9.14 No Hard-Coded Production Data

No production URLs, API keys, credentials, or secrets committed to the repo. Use `.env`/config.

## 9.15 Security Before Convenience

Never weaken tenant isolation, authentication, or the lender-visibility privacy layer just to make a feature ship faster. These are the product's actual trust model — see §4 and §6.

## 9.16 Verify Before Declaring Success

After a change: review the diff, check imports/syntax, check affected routes/migrations, run `php artisan test` and, for frontend changes, `npm run build`. Never claim a fix works without running it.

## 9.17 Protect Project Configuration

Take extra care modifying `composer.json`, `config/tenancy.php`, `config/database.php`, `routes/*.php`, `.env*` — these are application-wide. Don't touch them for a problem local to one feature.

## 9.18 Change Priority

```text
Correctness → Security (esp. tenant isolation & rate/visibility privacy) → Preservation of existing behavior
→ Consistency with current architecture → Maintainability → Performance → Developer convenience
```

---

# 10. Agent Changelog & Documentation Requirement

**CRITICAL RULE FOR ALL AGENTS:** after completing a significant feature, bug fix, integration, architectural change, or refactor, document it in this section (and in `CHANGELOG.md` once that file exists — create it with the [Keep a Changelog](https://keepachangelog.com) format on first use, under `[Unreleased]`). Skip trivial changes that add no useful historical context.

**Entry format:**

```markdown
**Date:** YYYY-MM-DD
**Agent Action:** Short description
**What was done:**
- Change 1
- Change 2
**Files changed:**
- path/to/file
**Why:** Reasoning behind the approach
**Method:** Technical explanation of how it was implemented
**Verification:** Build/test performed, relevant behavior checked
```

### Recent Changes Log

**Date:** 2026-10-05
**Agent Action:** Converted the generic `agents.txt` template (originally written for an unrelated project, "OneGPS") into this project-specific `agents.md`.
**What was done:**
- Read both planning docs in full (`cahtgpt_markdown_guide.md`, `deepseek_markdown_20261004_cb0be2.md`) and the actual `composer.json`/`package.json` of both repos.
- Discovered and flagged a real conflict: both planning docs specify Vue 3 + TypeScript, but the starter kit is wired for React 18 + Inertia with plain JS. Left unresolved pending user decision (§1.1).
- Removed the OneGPS-specific Google Analytics tag requirement and glassmorphism design-token rules (not applicable to this backend SaaS platform with no defined design system yet).
- Condensed and reconciled the two planning docs' architecture decisions (single-DB tenant_id isolation, roles, domain tables, critical implementation rules, build phases) into this file, while leaving exhaustive field-level schema in the original planning docs rather than duplicating ~3,900 lines.
**Files changed:**
- `agents.md` — created (this file)
- `agents.txt` — removed (superseded by this file)
**Why:** The original file was a copy-pasted template from a prior unrelated project and did not reflect this project's actual stack, domain, or state. The user asked for it to be converted to fit this project, with clarifying questions asked first.
**Method:** Asked the user 4 clarifying questions (project name, which planning doc is authoritative, repo layout decision, disposition of the OneGPS-specific design section), then read the relevant source files to verify facts before writing.
**Verification:** No build/test applicable — documentation-only change.

**Date:** 2026-10-05 (second pass)
**Agent Action:** Consolidated the two planning docs into one target design and recorded the user's stack decisions.
**What was done:**
- Read both planning docs end to end. The user clarified they are suggestions, not binding specs, and asked for a consolidation.
- Recorded confirmed decisions: Vue 3 + TypeScript frontend (the React starter gets migrated, §1.1), PostgreSQL, single database.
- Chose deepseek's lean schema as the V1 build and added a few low-cost items from the chatgpt guide (status history, offer versioning, platform audit log, narrow soft deletes, concurrency guards) — §5.1/§5.2. The chatgpt guide's fuller lender pipeline is kept as a deferred design in §5.3.
- Extended the critical rules (§6), tests (§7) and build order (§8), and added frontend structure, demo data and the MVP walk-through (§8.1/§8.2).
**Files changed:**
- `agents.md`
**Why:** Two overlapping specs at different ambition levels would otherwise leave every agent to re-decide which one to follow.
**Method:** Lean-first, following this file's own anti-overengineering principle; nothing from the richer design was discarded, only deferred.
**Verification:** Documentation-only. The schema choices in §5 are a judgment call the user has not yet reviewed line by line.

**Date:** 2026-10-05 (third pass)
**Agent Action:** Created `PLANNING.md`, the consolidated plan.
**What was done:**
- Wrote one self-contained planning document replacing the two drafts as the source for what is being built, including column-level schema.
- Beyond the earlier consolidation it fixes the application status list, adds `version` and `superseded` to offers, `reversed_entry_id` and a `reversal` type to the ledger, and unique constraints so one offer yields one loan and one loan one commission.
- Pointed this file at `PLANNING.md` for schema detail.
**Files changed:**
- `PLANNING.md` (new), `agents.md`
**Why:** The user asked for a single planning document.
**Method:** Deepseek's concrete schema and services as the base, with the chatgpt guide's process, tests, frontend and deferred pipeline folded in.
**Verification:** Documentation-only; nothing was run. Four decisions remain open in `PLANNING.md` §1.

**Date:** 2026-10-05 (fourth pass)
**Agent Action:** Recorded the user's four decisions, moved the app to the project root, completed the Phase 0 audit.
**What was done:**
- Recorded: app in the project root, email unique per tenant, Ghana defaults, PostgreSQL 17 on Neon.
- Copied every starter file except `screenshots/` into the project root. The original starter folder is unchanged.
- Audited the starter and LAMaS by reading source and wrote `docs/architecture/PHASE_0_AUDIT.md`.
- Copied LAMaS's two credit-risk seeders and its licence to `docs/architecture/lamas-reference/`.
- Corrected `PLANNING.md` where the audit contradicted it.
**Files changed:**
- Project root: all starter files (new copies)
- `docs/architecture/PHASE_0_AUDIT.md`, `docs/architecture/lamas-reference/*` (new)
- `PLANNING.md`, `agents.md`
**Why:** The user asked for Phase 0 and, mid-way, for the app to live in the project root.
**Method:** Read configs, models, migrations, routes, auth controllers, services, seeders and lock files of both repos; checked the local toolchain.
**Verification:** Nothing was installed or run. Local PHP is 8.3 without `pdo_pgsql`, and there is no Redis or PostgreSQL, so the app cannot run yet.

**Date:** 2026-11-24
**Agent Action:** Proved Phase 1 single-database tenancy and fixed the isolation tests.
**What was done:**
- Confirmed the single-database conversion already in the tree: no database bootstrapper, `BelongsToTenant` fail-closed scope, tenant-scoped auth and password reset, suspension middleware, no tenant deletion.
- Fixed the isolation tests to call `$tenant->run()`, which is the stancl/tenancy v3 API. `tenancy()->run()` does not exist in this version.
- Pointed PHPUnit at Redis so tenant cache tags have a store that supports them, and gave the suite an application key.
- Pointed `tenants:migrate` at the shared migrations directory. There is no `database/migrations/tenant` path.
- Aligned `PLANNING.md` with the fail-closed scope.
**Files changed:**
- `tests/Feature/Tenancy/TenantIsolationTest.php`
- `phpunit.xml`
- `config/tenancy.php`
- `PLANNING.md`
- `agents.md`
- `CHANGELOG.md`
**Why:** Phase 1 was implemented but had never been executed. The isolation tests called a method this version of stancl/tenancy does not provide, so the suite could not prove tenant isolation.
**Method:** Built the PHP 8.4 image from `docker/Dockerfile`, installed Composer dependencies, started PostgreSQL 17 and Redis from `compose.yaml`, and ran `php artisan test` inside the app container.
**Verification:** `docker compose run --rm app php artisan test` — 38 passed (83 assertions), including all 16 tenant-isolation tests.

---

# 11. Required Agent Workflow

**Step 1 — Understand:** read this file, the relevant section(s) of the two planning docs, and the relevant source files/components/routes.

**Step 2 — Investigate:** How does it currently work? Does the starter kit or LAMaS already implement something similar? Is this a bug or a missing feature? What's the root cause?

**Step 3 — Plan:** identify the files that genuinely need to change, what to reuse, the risks, and the expected behavior. If the plan touches tenant isolation, markup/rate integrity, or visibility privacy, say so explicitly before implementing.

**Step 4 — Implement:** the smallest correct change.

**Step 5 — Verify:** requested functionality, related existing functionality, tenant-isolation behavior if touched, runtime errors, build/test output.

**Step 6 — Clean:** remove temporary code, debug logs, obsolete imports.

**Step 7 — Document:** update this file's changelog (§10) and `CHANGELOG.md` for significant changes.

---

# 12. Final Standing Rules

1. Read `agents.md` before modifying the project.
2. Never edit `lamas-php-main/` — it is a read-only reference.
3. Build only in the project root. Never edit `laravel-multi-tenant-saas-starter-main/`.
4. The frontend is Vue 3 + TypeScript. Migrate the React starter in the §1.1 order; never keep both frameworks long-term.
5. Tenant isolation (§4) and the rate/visibility privacy rules (§3, §6) are non-negotiable; every other convenience is secondary to them.
6. Fix root causes rather than layering workarounds.
7. Reuse and extend existing code (starter kit infra, LAMaS domain concepts) before writing new code.
8. Make the smallest correct change; don't perform unrelated refactors.
9. Don't introduce dependencies, especially a second frontend framework or a second authorization system, without a documented reason.
10. Don't suppress errors to hide them.
11. Never expose secrets in frontend code or commit them to the repo.
12. Test tenant isolation and privacy-layer behavior whenever you touch code near them.
13. Document significant changes in `agents.md` and `CHANGELOG.md`.
14. When complexity unexpectedly explodes for what looked like a simple task, stop and reassess — it usually means the wrong extension point is being used.

---

# Golden Rule

> **Understand first. Reuse second. Modify third. Create new code only when necessary.**

And when something is broken:

> **Do not build a new solution on top of the problem. Find the cause and fix the problem at its source.**
