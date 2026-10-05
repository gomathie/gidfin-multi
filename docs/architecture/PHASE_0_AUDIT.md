# Phase 0 Audit

Audit of the starter kit and LAMaS, carried out on 2026-10-05 by reading the source. Nothing was installed or run: `vendor/` and `node_modules/` do not exist yet, so every statement here comes from the code, not from observed behaviour.

The starter kit's files have been copied into the project root, which is now the application. `laravel-multi-tenant-saas-starter-main/` and `lamas-php-main/` are untouched originals awaiting removal.

---

## 1. Blockers before any code can run

| # | Problem | Evidence | Fix |
|---|---|---|---|
| 1 | Local PHP is 8.3.2; the project needs 8.4 | `composer.json` requires `^8.4`; the lock file pins four Symfony 8.0 packages that require `>=8.4` | Install PHP 8.4, or run the app in Docker (Docker 29 is installed) |
| 2 | PHP has no PostgreSQL driver | `php -m` shows `pdo_mysql` and `pdo_sqlite` only | Enable `pdo_pgsql` and `pgsql` in the 8.4 install |
| 3 | No Redis | `.env.example` uses Redis for session, cache and queue with the `phpredis` extension; neither Redis nor the extension is present | Run Redis in Docker and install the extension, or use `predis` |
| 4 | No local PostgreSQL | `psql` not found | Use a Neon development branch, or PostgreSQL 17 in Docker |
| 5 | Not a git repository | — | `git init` before Phase 1 so every phase can be reviewed and reverted |

Redis cannot simply be swapped for the database or file cache driver: the tenancy package's cache separation relies on cache tags, which those drivers do not support.

Node 22 and Composer 2.9 are present and sufficient.

---

## 2. The starter kit as it stands

**Versions (from `composer.lock` and `package.json`):** Laravel 12.41, `stancl/tenancy` 3.9.1, Inertia Laravel 2.0, Sanctum 4.2, Ziggy 2.6; React 18, Tailwind 3, Vite 7. Breeze is a dev dependency only.

### 2.1 How tenancy works today

- `config/tenancy.php` enables four bootstrappers: database, cache, filesystem, queue.
- Each tenant gets its own database. `TenancyServiceProvider` runs `CreateDatabase → MigrateDatabase → SeedDatabase` when a tenant is created and `DeleteDatabase` when one is deleted.
- Tenant migrations live in `database/migrations/tenant/` (users, projects, personal access tokens). Central migrations live in `database/migrations/`.
- The tenant id **is the subdomain string** (`TenantService::createTenant` sets `id` to the subdomain). It is not a UUID, even though a UUID generator is configured.
- Tenants are resolved with `InitializeTenancyByDomain` on the full domain, e.g. `acme.localhost`.
- `Tenant` implements `TenantWithDatabase` and uses `HasDatabase`. Custom columns: `name`, `admin_email`, `is_active`.

### 2.2 Users and roles

- One `App\Models\User` class serves both sides. Which table it reads depends on which database is connected.
- Central users have a boolean `is_admin`. Tenant users have a `role` enum of `admin` or `user`.
- User ids are auto-increment integers.
- **There are no roles or permissions tables.** Access control is two middleware: `admin` (`EnsureUserIsAdmin`) and `tenant.admin` (`EnsureTenantAdmin`). There are no policies.

### 2.3 Routes

| File | Purpose |
|---|---|
| `routes/web.php` | Central domain group; loads `admin.php` and `api.php` |
| `routes/admin.php` | `/admin` login, dashboard, tenant CRUD, toggle status, login-as |
| `routes/tenant.php` | Tenant login, register, password reset, dashboard, users, projects, profile; loads `api-tenant.php` |
| `routes/api.php`, `routes/api-tenant.php` | Sanctum token APIs mirroring the web features |

### 2.4 What is reusable as-is

- Central admin: tenant CRUD, activate/deactivate, dashboard.
- Impersonation ("login as tenant") with a five-minute HMAC-signed link.
- Tenant login, registration, password reset, profile, user management.
- Service layer pattern (`TenantService`, `UserService`), form requests, API resources, `ApiResponse` trait.
- Inertia shared props (`auth.user`, `app`, `tenant`).
- The queue and filesystem bootstrappers. The queue one carries tenant context into jobs; the filesystem one gives each tenant its own storage path, which suits borrower documents.

### 2.5 What gets removed

The sample "projects" feature: model, two controllers, service, two requests, resource, migration, seeder, four pages and one unit test.

### 2.6 Frontend

36 `.jsx` files, about 3,600 lines: the app entry, 13 small components, 3 layouts, 6 central pages, 12 tenant pages (4 of them the projects feature), and a welcome page. Plain JavaScript with a `jsconfig.json`.

### 2.7 Tests

Six test files covering the central API, the impersonation service and two models. They run on in-memory SQLite. There are no tenant-isolation tests.

---

## 3. Gaps and risks found

These are things the plan did not know about.

1. **Suspended tenants are not blocked.** `is_active` is only checked when generating an impersonation link. A deactivated tenant's users can still log in and work. Needs a middleware in the tenant route group.
2. **Central login will match the wrong user once tables merge.** `Auth::attempt` looks up by email alone. With one `users` table and per-tenant emails, the central login must add `tenant_id = null`, and tenant login must add the current tenant. The same applies to the two API `AuthController`s, which use `User::where('email', …)`.
3. **Password reset breaks with per-tenant emails.** `password_reset_tokens` uses `email` as its primary key, and the password broker finds users by email alone. Needs a tenant-aware key and lookup.
4. **Five validation rules assume globally unique emails**: registration, user create, user update, profile update and the tenant API's register. All must be scoped to the tenant.
5. **Impersonation looks up the tenant admin by email** and falls back to the first admin. It needs explicit tenant scoping and should write to the platform audit log.
6. **Deleting a tenant would cascade.** With `tenant_id` foreign keys set to cascade, deleting a tenant row would erase its loans and ledger. Tenant deletion should be disabled in favour of a cancelled status.
7. **Sessions are not tenant-separated.** Session cookies are per host, which is adequate, but nothing ties a session to a tenant. The tenant scope on `User` closes this: a user id from another tenant will not resolve.
8. **The scope is silent outside tenant context.** As planned, `BelongsToTenant` filters nothing when no tenant is set. On central routes that means every tenant's rows are visible. Central code must therefore never touch business models except in the sanctioned dashboard queries.
9. **Tests run on SQLite.** Partial indexes, enums and JSONB behave differently there. Tests should run on PostgreSQL.
10. **`impersonated_by_admin` is written to the session but never read.** No banner, no audit trail.

---

## 4. Corrections to the plan

| Plan said | Reality | Correction |
|---|---|---|
| `tenant_id` is a UUID | Tenant ids are subdomain strings | `tenant_id` columns are strings referencing `tenants.id` |
| Foreign keys to users are UUIDs | User ids are integers | `changed_by`, `created_by`, `uploaded_by` and similar are `foreignId` |
| Roles and permissions tables come from the starter | There are none | V1 uses a `role` column on `users` plus one role middleware and policies. No Spatie package |
| `tenants.status` enum | Starter has boolean `is_active` | Replace with `status` (active / suspended / cancelled) and update the central pages |
| `tenants.company_name` | Starter column is `name` | Keep `name` |
| Remove only the database bootstrapper | The tenant model and service provider also depend on per-tenant databases | See §6 |

Business tables still use UUID primary keys.

---

## 5. LAMaS: what is worth keeping

LAMaS is an intake system. It records a customer and a loan request and lets staff move the request through statuses. It has **no pricing logic, no lender concept, no ledger, no repayment schedule and no commission calculation**: the rate is a number typed into a form. Nothing in it informs the financial core of this platform.

### 5.1 Mapping

| LAMaS | Here | Notes |
|---|---|---|
| `customers` + `customer_details` | `borrowers` | LAMaS splits identity and personal details; the plan keeps one table |
| `loan_applications` + `loan_application_details` | `applications` | LAMaS statuses: received, verified, assigned, analyzed, approved, rejected, archived |
| `loan_application_notes` | — | Not in the plan; see §5.2 |
| `promoters` | `agents` | Adds bonus type/value and bank account details |
| `brokers`, `portfolios` | — | A broker owns a portfolio of customers. Here the agent relationship covers it |
| `companies` | — | The customer's employer, not a tenant. The plan stores `employer_name` only |
| `credit_risks`, `credit_risk_categories` | `borrowers.risk_tier` | A catalogue of 55 named risk flags in 7 categories, attached to applications many-to-many |
| `phones`, `addresses` (polymorphic) | — | The plan stores one phone and no address |
| Jetstream teams, Spatie roles | — | Not carried over |

LAMaS roles, for reference: admin, manager, supervisor, broker, promoter, analyst, secretary, trainee, legal, collector.

### 5.2 Ideas the plan is missing

These are candidates, not decisions.

1. **Borrower address.** LAMaS requires a home address. The plan's `borrowers` table has none, yet the lender visibility settings already include `show_borrower_address`. This is a gap in the plan and should be fixed.
2. **Borrower references / guarantors.** LAMaS requires at least one reference with a phone number.
3. **Application notes.** Internal staff notes per application, each with an author.
4. **Agent payout details.** Bank or mobile-money account for paying commission.
5. **Repayment frequency.** LAMaS supports weekly, fortnightly and monthly instalments. The plan is monthly only (`tenure_months`, monthly amortisation).
6. **Richer borrower profile.** Gender, marital status, housing situation, employment start date, pay frequency, other income.
7. **Risk flags.** The 55-item catalogue could back a checklist on the application instead of a single free-text `risk_tier`.
8. **Lead source and referral.** `lead_channel`, `is_referred`, `referred_by`.

### 5.3 Preserved from LAMaS

`docs/architecture/lamas-reference/` holds the two credit-risk seeders (the only substantial reference data in LAMaS) and its Apache 2.0 licence. Everything else of value is described in this section, so the LAMaS folder can be removed.

Risk categories and counts: account movements (5), banking and credit history (8), personal/family situation (9), employment (8), financial status (8), payment capacity and morale (7), guarantees (10).

### 5.4 Not to copy

Money stored as `float` and `integer`; application state held in six redundant booleans alongside the status column; controllers of 550–650 lines; validation that disagrees with the migration's allowed values.

---

## 6. File-change plan

### Phase 1 — single-database tenancy

| File | Change |
|---|---|
| `config/tenancy.php` | Remove `DatabaseTenancyBootstrapper`; drop the tenant migration and seeder parameters |
| `app/Providers/TenancyServiceProvider.php` | Remove the create/migrate/seed and delete-database pipelines; on tenant creation, create the tenant's admin user instead |
| `app/Models/Tenant.php` | Drop `TenantWithDatabase` and `HasDatabase`; `is_active` → `status` |
| `app/Models/Concerns/BelongsToTenant.php` | New |
| `app/Models/User.php` | Add `tenant_id` and the new roles; apply tenant scoping; remove `projects()` |
| `database/migrations/` | Merge the tenant `users` columns into the central table: `tenant_id`, `role`, `UNIQUE (tenant_id, email)`, partial unique index for tenant-less users; rework `password_reset_tokens`; `tenants.status` |
| `database/migrations/tenant/` | Delete |
| `app/Http/Controllers/*/Auth/*`, `Api/AuthController.php`, `Api/Tenant/AuthController.php` | Scope every email lookup to the tenant (or to no tenant on central) |
| `app/Http/Requests/Tenant/*`, registration controller | Tenant-scoped unique rules |
| `app/Http/Middleware/` | New: block suspended tenants; new: role check replacing `tenant.admin` |
| `app/Services/TenantService.php` | No database provisioning; disable hard delete |
| `app/Http/Controllers/Tenant/ImpersonationController.php` | Tenant-scoped lookup |
| `database/seeders/` | Rewrite for one database; remove `tenant/` seeders |
| Projects feature (§2.5) | Delete |
| `phpunit.xml`, `.env.example` | PostgreSQL for tests and development; Neon pooled and direct URLs |
| `tests/Feature/Tenancy/` | New isolation tests, including queued jobs and the suspended-tenant case |

### Phase 2 — Vue migration

| Step | Files |
|---|---|
| Tooling | `package.json`, `vite.config.js` → `vite.config.ts`, `tsconfig.json` replacing `jsconfig.json`, `resources/js/app.ts`, `resources/views/app.blade.php` |
| Components | 13 `.jsx` → `.vue` |
| Layouts | Central, Guest, Tenant |
| Central pages | Login, Dashboard, Tenants (Index, Create, Edit, Show) |
| Tenant pages | Login, Register, ForgotPassword, ResetPassword, Dashboard, Profile, Users |
| Not ported | The four Projects pages; `Welcome.jsx` is replaced by a new landing page |
| Last | Remove `react`, `react-dom`, `@inertiajs/react`, `@headlessui/react`, `@vitejs/plugin-react` |

Roughly 2,800 lines of JSX to port once the projects pages are excluded.

---

## 7. Questions for the product owner

1. Is repayment monthly only, or are weekly and fortnightly instalments needed?
2. Which of the §5.2 ideas belong in V1? Borrower address is recommended regardless.
3. Local environment: install PHP 8.4 natively, or run everything in Docker?
