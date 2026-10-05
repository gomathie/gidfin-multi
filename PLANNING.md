# LoanBroker Platform — Consolidated Plan

This is the single planning document for the build. It consolidates two earlier AI-generated drafts (`deepseek_markdown_20261004_cb0be2.md` and `cahtgpt_markdown_guide.md`), which were suggestions rather than binding specs. Where they disagreed, this document picks one answer. `agents.md` holds the working rules for agents; this file holds what is being built.

---

## 1. Decisions

| Topic | Decision | Status |
|---|---|---|
| Database | PostgreSQL 17, hosted on Neon | Confirmed (version chosen here) |
| Tenancy model | One database for the whole platform; rows scoped by `tenant_id` | Confirmed |
| Frontend | Vue 3 + Inertia.js + TypeScript + Tailwind CSS + Vite | Confirmed |
| Backend | Laravel 12, PHP 8.4+, `stancl/tenancy` 3.x for domain identification only | From the starter kit |
| Application folder | The project root (`multitenant-loan/`). The starter kit's files were copied into it on 2026-10-05 | Confirmed |
| Reference folders | `laravel-multi-tenant-saas-starter-main` (pristine starter) and `lamas-php-main` (LAMaS) are read-only and will be removed later | Confirmed |
| Identifiers | Business tables use UUID keys; `tenant_id` is a string (the subdomain); user ids are integers | From the Phase 0 audit |
| Authorization | A `role` column on `users`, one role middleware, and policies. No permissions package in V1 | From the Phase 0 audit |
| User email | Unique per tenant, not globally | Confirmed |
| Market defaults | Ghana: country `GH`, currency `GHS`, timezone `Africa/Accra` | Country confirmed; currency and timezone follow from it |
| Schema size | Lean 15-table V1; the fuller pipeline is deferred (§14) | Proposed in this document |

The Phase 0 audit (`docs/architecture/PHASE_0_AUDIT.md`) corrected several assumptions in this plan and lists the file changes for Phases 1 and 2. Where the two disagree, the audit reflects the actual code.

### Open questions

1. Is repayment monthly only, or are weekly and fortnightly instalments needed? The schema and amortisation below assume monthly.
2. Which borrower and agent additions from the audit (§5.2) belong in V1: address, references or guarantors, application notes, agent payout account, risk-flag checklist?
3. Local environment: PHP 8.4 installed natively, or Docker? The machine currently has PHP 8.3 without a PostgreSQL driver and no Redis, so nothing can run yet.

### What these decisions imply

**Neon**

- Use PostgreSQL 17 locally as well, so development matches production.
- Neon gives two connection strings. The app uses the pooled one; migrations and anything needing session state use the direct one. Keep both in `.env`.
- The pooled connection runs in transaction mode, so session-level settings do not persist between queries. This is one more reason tenant isolation is enforced in the application, and it would constrain any later use of Row Level Security.
- Neon scales to zero when idle, so the first request after a pause is slow. Keep this in mind for queue workers and health checks.

**Email unique per tenant**

- `users` gets `UNIQUE (tenant_id, email)`.
- Super admins have no tenant, and PostgreSQL treats nulls as distinct, so add a partial unique index on `email` where `tenant_id` is null.
- Login, registration and password reset must look users up by email within the current tenant. The starter's auth validation assumes a globally unique email and has to be changed in Phase 1.
- The same person at two agencies has two separate accounts with separate passwords.

**Ghana**

- `national_id` holds the Ghana Card number.
- Because the LAMaS folder will be removed, Phase 0 must capture everything worth keeping from it in writing.

---

## 2. Product

A SaaS platform for loan agencies and independent loan agents. Each agency or independent agent is a tenant. The agency is the only link between lenders and borrowers.

```text
LENDER  ◄──►  AGENCY / AGENT  ◄──►  BORROWER
```

Flow:

```text
Borrower → Agent → Application → Eligible lender products → Offer (lender rate + agency markup)
→ Borrower accepts → Lender approves → Loan → Disbursement → Ledger → Commission
```

Money flows Lender → Agency → Borrower on disbursement, and Borrower → Agency → Lender on repayment. The markup is the agency's revenue.

### Two rules everything else serves

**Rate integrity.** Three values are always stored separately and never collapsed:

```text
lender_base_rate   12.00%
agency markup       3.00%
customer rate      15.00%
```

**Visibility.** The borrower never sees the lender's base rate or the markup. The lender sees only what the agency allows, per lender, field by field.

### Out of scope for V1

AI chatbot, AI underwriting or lender recommendation, OCR, automatic ID or bank-statement extraction, Open Banking, credit-bureau automation, automated repayments, direct debit, payment gateways, collections and debt-recovery automation, predictive scoring, advanced analytics, accounting integrations, lender API automation.

---

## 3. Roles

| Role | Scope | Purpose |
|---|---|---|
| Super Admin | Platform | Creates and manages tenants; platform dashboard |
| Agency Admin | Tenant | Markup caps, lender visibility, agents; agency dashboard |
| Agency Manager | Tenant | Oversees agents and portfolio; team dashboard |
| Agent | Tenant | Onboards lenders, registers borrowers, sets markup; personal dashboard |
| Lender User | Tenant + one lender | Defines products, approves or rejects; sees only permitted data |
| Borrower | Tenant | Views offers, accepts or rejects, repays |

A tenant is either an `agency` or an `independent_agent`. The independent agent's owner also has an Agent profile. There is no separate Agency model; the tenant is the agency.

The starter has no roles or permissions tables, only an `is_admin` flag and an admin/user role column. V1 extends that column to the six roles above and adds policies; no permissions package. Finer roles (compliance, auditor, underwriter, platform support) are added only when a feature needs them.

---

## 4. Source repositories

**Starter kit (base).** Owns infrastructure: authentication, tenant resolution, central admin, routing, middleware, queues, cache, storage, notifications. Preserve what works. Two things change:

- Tenancy mode: it ships database-per-tenant and must become single-database (§5).
- Frontend: it ships React 18 with plain JavaScript and must become Vue 3 with TypeScript (§10).

Its sample "projects" feature is a placeholder to be replaced by the loan domain.

**LAMaS (reference only).** Never edited, never copied wholesale. It runs Laravel 11 with Jetstream, Livewire and AdminLTE, none of which carry over. Starting hypothesis for the domain mapping, to be confirmed against its source:

| LAMaS | Here |
|---|---|
| Customer | Borrower |
| LoanApplication | Application |
| Promoter | Agent |
| Broker, Company | Tenant |
| CreditRisk | Borrower risk tier; fuller reuse later |
| Portfolio | Reporting ideas for loans |

---

## 5. Tenancy and isolation

- One PostgreSQL database. All migrations live in `database/migrations/` and run once. `database/migrations/tenant/` is not used.
- Remove `DatabaseTenancyBootstrapper` from `config/tenancy.php`. Keep domain identification, and keep the cache, queue and filesystem bootstrappers if the starter uses them.
- Tenants are identified by subdomain (`acme.localhost`).
- Creating a tenant inserts a tenant row and a domain row. No database is created.

Isolation is enforced in five layers:

1. **Tenant context** resolved before any business query runs.
2. **`BelongsToTenant` trait** on every business model: fills `tenant_id` on create and adds a global scope.
3. **Policies** that check both the user's permission and the resource's tenant.
4. **Database constraints**: foreign keys, and unique indexes that include `tenant_id`.
5. **Automated tests** (§11).

```php
namespace App\Models\Concerns;

use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (empty($model->tenant_id) && tenant()) {
                $model->tenant_id = tenant()->id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $query) {
            if (tenant()) {
                $query->where($query->getModel()->getTable() . '.tenant_id', tenant()->id);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
```

Note that this scope does nothing when no tenant is resolved (queues, CLI, central routes). Jobs that touch business tables must set the tenant context explicitly, and Phase 1 must test that path.

`withoutGlobalScope('tenant')` is allowed only in tests and in platform-level aggregate queries, with a comment saying why. Avoid `DB::table()` on business tables, since it bypasses the scope.

Lender users are scoped twice: current tenant and their own lender.

PostgreSQL Row Level Security is a possible later addition. It does not replace application-level scoping.

---

## 6. Schema

Conventions for every business table:

- `id` UUID primary key (`HasUuids`); `tenant_id` string, foreign key to `tenants.id`, restrict on delete, indexed; timestamps. Tenants are cancelled, never deleted, so a cascade can never erase financial records.
- Columns that reference users (`created_by`, `changed_by`, `uploaded_by`, `user_id`) are integer foreign keys.
- Money is `decimal(15,2)`. Rates are `decimal(6,3)`. Never float or double.
- Unique constraints include `tenant_id`.
- Indexes lead with `tenant_id` and follow real query patterns, e.g. `(tenant_id, status)`.
- JSONB only for flexible data (metadata, rule sets, snapshots).

### 6.1 Central tables

**`tenants`** — id (string PK, the subdomain), name, admin_email, tenant_type (agency / independent_agent), registration_number, country_code (default `GH`), currency (default `GHS`), timezone (default `Africa/Accra`), contact_email, contact_phone, settings (jsonb), subscription_plan (starter / professional / enterprise), status (active / suspended / cancelled), data (jsonb, used by the tenancy package), timestamps.

**`domains`** — id, domain (unique), tenant_id, timestamps. From the starter.

**`users`** — the starter's columns plus: tenant_id (nullable; null means super admin), role (super_admin / agency_admin / agency_manager / agent / lender / borrower), agent_id, borrower_id, lender_id (all nullable). Unique (tenant_id, email), plus a partial unique index on email where tenant_id is null.

**`password_reset_tokens`** — reworked so a token belongs to a tenant and email together.

There are no roles or permissions tables; the starter has none and V1 does not add them.

**`platform_audit_logs`** — actor user, event type, subject type and id, old and new values (jsonb), ip address, created_at. Records tenant creation, suspension and reactivation, domain changes, and impersonation start and end.

### 6.2 Business tables

1. **`lenders`** — name, slug, contact_email, contact_phone, address, country_code, metadata (jsonb), status (active / inactive / suspended), onboarded_by_agent_id (nullable), deleted_at. Unique (tenant_id, slug). Two tenants may each have a lender with the same name; they are separate records.

2. **`lender_visibility_settings`** — lender_id (unique with tenant_id), then one boolean per field:

   | Setting | Default |
   |---|---|
   | show_borrower_name | true |
   | show_borrower_contact | false |
   | show_borrower_address | false |
   | show_borrower_employment | true |
   | show_borrower_income | true |
   | show_borrower_risk_tier | true |
   | show_borrower_national_id | false |
   | show_borrower_documents | true |
   | show_final_rate | false |
   | show_markup | false |
   | show_competing_lenders | false |
   | show_agency_notes | false |

   Plus allowed_document_types (jsonb, null means all), custom_rules (jsonb), updated_by.

3. **`loan_products`** — lender_id, name, description, min_amount, max_amount, min_tenure_months, max_tenure_months, base_rate, currency (default `GHS`), eligibility_rules (jsonb), required_documents (jsonb), approval_rules (jsonb), status (active / inactive / archived), deleted_at.

4. **`agents`** — user_id, employee_code, first_name, last_name, email, phone, date_of_birth, national_id, tier (junior / senior / principal), commission_share_pct (default 50.00), status (active / inactive / suspended), deleted_at. Unique (tenant_id, employee_code) and (tenant_id, email).

5. **`borrowers`** — user_id (nullable), first_name, last_name, email, phone, date_of_birth, national_id, employment_status, employer_name, annual_income, risk_tier, kyc_status (pending / verified / rejected), registered_by_agent_id, metadata (jsonb), deleted_at.

6. **`applications`** — application_no, borrower_id, agent_id, requested_amount, requested_tenure_months, purpose, status, submitted_at. Unique (tenant_id, application_no). An application has no single lender; it can produce offers from several.

   Statuses: `draft → submitted → under_review → approved → disbursed → closed`, with `rejected`, `withdrawn`, `cancelled` and `expired` as terminal exits. Only `ApplicationWorkflowService` changes status.

7. **`application_status_history`** — application_id, from_status, to_status, reason (nullable), changed_by, created_at. One row per transition.

8. **`application_documents`** — application_id, doc_type, storage path on a private disk, original filename, mime type, uploaded_by, verified, verified_at. Every download passes an authorization check.

9. **`offers`** — the core record.
   - Identity: offer_no, version (default 1), application_id, lender_id, loan_product_id, created_by (agent).
   - Terms: principal_amount, tenure_months, lender_base_rate.
   - Markup: markup_type (percentage / flat_fee / combined / none), markup_percentage, markup_flat_fee, agent_notes.
   - Guardrail snapshot: max_percentage_allowed, max_flat_fee_allowed.
   - Computed: final_rate, final_fee, apr, monthly_payment, total_repayable, total_lender_due, total_markup_earned.
   - Lifecycle: status (draft / sent / viewed / accepted / rejected / expired / superseded), sent_at, responded_at, expires_at.
   - Unique (tenant_id, offer_no, version).

   A draft offer can be edited. Once sent, a change creates a new row with the next version and marks the old row `superseded`.

10. **`loans`** — loan_no, offer_id, application_id, borrower_id, lender_id, agent_id, and a snapshot of principal_amount, lender_base_rate, final_rate, markup_percentage, markup_flat_fee, monthly_payment, total_repayable, total_lender_due, total_markup_earned; status (pending_lender / approved / rejected / disbursed / active / repaid / defaulted / written_off), lender_approved_at, disbursed_at, closed_at. Unique (tenant_id, loan_no), and unique (tenant_id, offer_id) so one offer yields one loan.

11. **`loan_ledger`** — loan_id, entry_type (disbursement / borrower_payment / lender_payout / agency_revenue / agent_commission / adjustment / reversal), counterparty (lender / borrower / agency / agent), amount, reference, reversed_entry_id (nullable), metadata (jsonb), recorded_at. Append-only.

12. **`repayment_schedule`** — loan_id, installment_no, due_date, principal_due, interest_due, fee_due, total_due, status (pending / paid / overdue / waived), paid_at.

13. **`markup_limits`** — scope_type (agency / lender / product), scope_id (nullable), allow_percentage, allow_flat_fee, allow_combined, max_percentage, max_flat_fee, created_by.

14. **`commissions`** — loan_id (unique with tenant_id), agent_id, total_markup, agent_share, agency_share, status (pending / payable / paid), payable_at, paid_at.

15. **`audit_logs`** — bigserial id, tenant_id, entity_type, entity_id, action, field_changed, old_value, new_value, reason, changed_by, changed_at. Append-only.

Soft deletes apply only to lenders, loan products, agents and borrowers. Offers, loans, ledger entries, commissions and audit rows are never deleted or edited; mistakes are corrected with reversal entries.

---

## 7. Services

Business rules live in services. Controllers stay thin.

**`MarkupService`** — the only place markup is validated.
- `resolveGuardrails(lenderId, productId)`: collects matching `markup_limits` at agency, lender and product scope. A markup type is allowed only if every matching limit allows it; the maximums are the lowest across matching limits. With no limits, nothing is allowed.
- `validateMarkup(guardrails, type, pct, flat)`: throws on negative values, disallowed types, or values above the maximum.
- `computeOffer(principal, months, baseRate, type, pct, flat)`: returns final_rate, final_fee, monthly_payment, total_repayable, total_lender_due, total_markup_earned.
- `amortizedPayment(principal, annualRate, months)`: with `r = annualRate / 100 / 12`, returns `principal / months` when `r = 0`, otherwise `principal × r / (1 − (1 + r)^−months)`.

**`ApplicationWorkflowService`** — validates each status transition, writes `application_status_history`, rejects invalid jumps.

**`LenderMatchService`** — `getEligibleProducts(amount, months)`: active products whose amount and tenure ranges fit. Eligible does not mean approved.

**`LenderVisibilityService`** — `forLender` (creates defaults if missing), `canSee(lender, field)`, `filterDocuments`, `updateSettings` (audited).

**`LoanProvisioningService`**
- `provisionFromOffer(offer)`, in one transaction with the offer row locked: create the loan from the offer snapshot (`pending_lender`), generate the repayment schedule (first instalment due one month later), create the commission split by the agent's `commission_share_pct`.
- `markDisbursed(loan)`, in one transaction: update the loan and insert one disbursement ledger entry.

**`LoanLedgerService`**
- `recordPayment(loan, installment, amount)`, in one transaction: insert three ledger rows (borrower_payment, lender_payout, agency_revenue), mark the instalment paid, close the loan when all are paid.
- `getLoanSummary(loan)`: totals per entry type.

**`AuditService`** — `log(entityType, entityId, action, field, old, new, reason)`; fills tenant and actor automatically.

**Dashboard services** — one per role under `app/Services/Dashboards/` (§9).

Offer acceptance, loan creation, disbursement and commission creation use row locks plus the unique constraints in §6 so a repeated or concurrent request cannot produce duplicates.

---

## 8. HTTP layer

### 8.1 Privacy through API Resources

A raw `Offer` model is never returned. Each audience has its own resource.

| Resource | Returns | Never returns |
|---|---|---|
| `BorrowerOfferResource` | offer_no, lender name, principal, tenure, final_rate, final_fee, apr, monthly_payment, total_repayable, status, expires_at | lender_base_rate, any markup field, total_markup_earned |
| `LenderApplicationResource` | application basics, the lender's own base rate, and each borrower field, document, final rate, markup, agency note or competing-lender name only if the matching visibility setting is on | anything a setting has switched off |
| `AgentOfferResource` | everything | — |

### 8.2 Controllers

- **Central:** `TenantController` (CRUD), `DashboardController`.
- **Tenant:** `LenderController`, `LenderVisibilityController`, `LoanProductController`, `AgentController`, `BorrowerController`, `ApplicationController` (plus eligible products, submit), `OfferController` (plus send, accept, submit to lender), `MarkupLimitController`, `LoanController` (plus create from offer, disburse), `CommissionController`, `DashboardController`, `ReportController` (markup outliers, agent performance, lender performance).
- **Borrower-facing:** offers (list, show, accept, reject, with ownership checked on every action), dashboard.
- **Lender-facing:** applications (list, approve, reject), dashboard.
- **Agent-facing:** dashboard.

Creating an offer: validate the request → load the product → resolve guardrails → validate markup → compute → store the offer with the guardrail snapshot and computed values → audit → return `AgentOfferResource`.

### 8.3 Route access

| Area | Prefix | Roles |
|---|---|---|
| Platform admin | `/admin` on the central domain | super_admin |
| Agency dashboard and reports | `/dashboard`, `/reports/*` | agency_admin, agency_manager |
| Lender visibility, markup limits | `/lenders/{lender}/visibility`, `/markup-limits` | agency_admin |
| Lenders, products, borrowers, applications, offers, loans, commissions | tenant root | agency_admin, agency_manager, agent |
| Agent dashboard | `/agent` | agent |
| Borrower area | `/my` | borrower |
| Lender area | `/lender` | lender |

Follow the starter's namespace and route-file conventions.

---

## 9. Dashboards

| Dashboard | Contents |
|---|---|
| Super admin | Agency counts, users, loans originated, volume, revenue; top agencies by volume; recent agencies; 12-month originations; per-tenant record counts |
| Agency admin | Portfolio totals and revenue; application pipeline by status; offers by status; recent offers; agent leaderboard; lender performance (applications, approval rate, volume, average rate); markup distribution and outliers; compliance alerts (offers at the ceiling, offers changed after sending, recent visibility changes); 12-month trend |
| Agency manager | Team agents, team pipeline, this month, recent activity |
| Agent | Pipeline and conversion rate; active loans and volume; earnings (pending, payable, paid this year, lifetime); lenders onboarded; documents outstanding; recent offers; 6-month trend |
| Lender | Own products; pending applications and approval rate; own portfolio; recent applications filtered by visibility; 12-month trend |
| Borrower | Applications; active offers; loans, outstanding balance and next payment; upcoming instalments; payment history |

The super admin dashboard aggregates across tenants and is the main sanctioned use of `withoutGlobalScope('tenant')`.

---

## 10. Frontend

Vue 3 with `<script setup lang="ts">`, Inertia, Tailwind, Vite. The app should be installable as a PWA; this is not a V1 blocker.

### 10.1 Migrating the starter from React

1. Inventory the existing React pages.
2. Add Vue 3, `@inertiajs/vue3` and TypeScript, with a Vue bootstrap in `resources/js/app.ts`.
3. Port authentication, then central admin, then the tenant shell.
4. Port the remaining tenant pages.
5. Confirm parity with the React version.
6. Remove React and its Inertia adapter and Vite plugin.

React and Vue do not coexist beyond the migration.

### 10.2 Structure

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

### 10.3 UX

- The application detail page is the main workspace: summary, borrower, requested loan, documents, eligible products, offers, selected offer, loan and activity on one screen.
- Offer comparison shows lender, product, principal, term, customer rate, repayment, fees, total repayable, expiry and action. Agency users also see base rate, markup and margin.
- Hiding a control with `v-if` is for usability only. Laravel enforces every permission, and no persisted financial figure is calculated in Vue.

---

## 11. Tests

**Tenant isolation**
- Data created under Tenant A is invisible under Tenant B, and visible again with the scope removed.
- Tenant A cannot read or update Tenant B's borrowers, lenders, applications, offers, loans, ledger, commissions or audit rows, including by guessed UUID.
- Relationships (`$application->borrower`, `$lender->loanProducts`, `$loan->offer`, `$commission->agent`) never cross tenants.
- A static check that `withoutGlobalScope`, `DB::table` and raw SQL in `app/` do not touch business tables outside sanctioned places.
- A suspended tenant cannot use the application; a tenant admin cannot reach platform admin.

**Pricing**
- Percentage and flat-fee markups compute correctly (10% + 3% = 13%).
- Above-maximum, disallowed-type and negative markups throw.
- An agent cannot change a lender's base rate.
- After a product's rate changes, existing offers and loans keep their original figures.

**Privacy**
- Borrower responses contain no base rate or markup fields.
- Lender responses follow each visibility toggle, including document-type filtering.
- One lender's user cannot see another lender's applications.

**Workflow and money**
- Invalid application transitions fail; the valid path and terminal states work and write history.
- Accepting an offer and creating a loan yields one loan, the full schedule and one commission, even if repeated.
- Disbursement writes one ledger entry; a payment writes three.

**Access**
- Each role reaches its own dashboard; other roles get 403.

A phase is complete only when its tests pass.

---

## 12. Build phases

| Phase | Work | Exit condition |
|---|---|---|
| 0 | Audit both repos; write the starter's current architecture, the LAMaS concepts worth using, and the file-change plan. **Done** — see `docs/architecture/PHASE_0_AUDIT.md` | Everything needed from LAMaS is written down |
| 1 | Single-database tenancy: tenancy config, `BelongsToTenant`, tenant creation, domain routing, tenant auth, queues, cache, storage, suspension | Isolation tests pass |
| 2 | Vue migration (§10.1) | Parity reached, React removed |
| 3 | Central schema: tenant and user extensions, roles, platform audit log | |
| 4 | Business migrations (§6.2) | `php artisan migrate` runs clean |
| 5 | Models | |
| 6 | Services (§7) | Unit tests pass |
| 7 | Controllers, resources, routes and Vue pages | Privacy and workflow tests pass |
| 8 | Dashboards | Access tests pass |
| 9 | Hardening and release readiness: IDOR, document access, mass assignment, rate limiting, queue/cache/storage/session tenancy, indexes and N+1s, fresh install, production build | MVP walk-through passes |

Within phases 4 to 7, work in domain order: agents, borrowers, lenders and lender users, loan products, applications, matching, offers and pricing, loans, ledger, commissions.

After each phase, stop and show the files changed, the commands run and their output before starting the next.

---

## 13. Demo data and MVP acceptance

Seed one realistic tenant — "Prime Loan Agency" with an admin, two agents, three lenders (ABC Finance, XYZ Credit, Capital Lending), five products, several borrowers and applications — and a second unrelated tenant to demonstrate isolation.

The MVP is complete when this runs end to end:

1. Platform admin creates Tenant A; the agency admin logs in.
2. The agency creates an agent, three lenders, lender users, products and markup limits.
3. The agent creates a borrower, uploads documents and creates an application.
4. Eligible products are identified.
5. The agent creates offers; markup above the limit is refused.
6. Lender users see only their own applications; one approves, one rejects.
7. The agent compares offers and the borrower accepts one, seeing no markup.
8. The application moves through its statuses with history recorded.
9. A loan is created with snapshotted terms, a schedule and a commission.
10. Disbursement is recorded and appears in the ledger.
11. A repayment writes its three ledger entries.
12. Agency and agent commission shares are stored.
13. The audit log shows each critical action.
14. Tenant B can see none of Tenant A's data.

---

## 14. Deferred: the fuller pipeline

The chatgpt draft described a larger design. These pieces are left out of V1 and added only when a real need appears.

| Piece | What it adds |
|---|---|
| `lender_matches` | A stored record of why each product was or was not eligible for an application |
| `lender_submissions` | A lender review lifecycle with "more information required" and "countered" states |
| `lender_quotes` | The lender's terms as their own versioned record, separate from the customer offer |
| `markup_rules` | Configurable default markups by lender, product, amount or term |
| `pricing_overrides` | An approval workflow for markup above the normal limit |
| `loan_disbursements` | A record-then-verify step before a disbursement reaches the ledger |
| `commission_rules` | Commission that varies by lender or product, not only by agent |
| `loan_product_rules` | Eligibility rules as rows (field, operator, value) instead of JSONB |
| Borrower address, employment and income tables | Multiple or historical entries per borrower |
| Finer roles | Compliance, auditor, underwriter, platform support and auditor |
| Row Level Security, composite `(tenant_id, id)` foreign keys | Database-level isolation on top of application scoping |
