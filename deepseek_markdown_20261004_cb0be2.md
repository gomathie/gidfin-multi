# Implementation Task: Single-DB Multi-Tenant Loan Agency Platform

You are implementing a multi-tenant SaaS platform for loan brokerages.
The agency is the sole link between lenders ("bank rollers") and borrowers.

## Architecture Decision (READ FIRST)

**Isolation strategy: SINGLE CENTRAL DATABASE with `tenant_id` scoping.**

- ONE PostgreSQL database for the entire platform
- Every business table has a `tenant_id` UUID column referencing `tenants.id`
- A `BelongsToTenant` trait applies a global Eloquent scope that auto-filters every query by the current tenant
- Tenant is identified by subdomain (`acme.localhost`, `swift.localhost`) using Stancl/Tenancy's domain identification
- **REMOVE `DatabaseTenancyBootstrapper`** from `config/tenancy.php` — no per-tenant databases
- **DO NOT** use `database/migrations/tenant/` — all migrations live in `database/migrations/`
- Migrations run ONCE for the whole platform

## Context: Existing Repositories

Two repos are available locally. Read both before writing any code.

### Repo 1 — Multi-tenant starter kit (BASE)
Location: `./laravel-multi-tenant-saas-starter/`
- Laravel 11 + Stancl/Tenancy
- Currently configured for DB-per-tenant. **Convert to single-DB mode:**
  - In `config/tenancy.php`, remove `DatabaseTenancyBootstrapper` from the bootstrappers array
  - Keep only `DomainTenantIdentificationMiddleware` (or equivalent) for subdomain routing
  - Keep cache/queue/filesystem bootstrappers if the starter kit uses them
- Provides: tenants, domains, central admin panel, Sanctum auth, RBAC
- Study `config/tenancy.php`, `config/database.php`, and middleware registration

### Repo 2 — LAMaS (DOMAIN REFERENCE ONLY)
Location: `./lamas/`
- Reference models: `Customer`, `LoanApplication`, `Broker`, `Promoter`, `CreditRisk`
- Reference patterns: polymorphic `Address` and `Phone`
- **DO NOT copy files directly.** Use only for entity relationship inspiration.

## Business Model

```
LENDER (bank roller)  ◄──►  AGENCY / AGENT  ◄──►  BORROWER
```

- The agency is the ONLY link between lender and borrower
- Borrower NEVER sees the lender's base rate or the agent's markup
- Lender sees ONLY what the AGENCY ALLOWS it to see (agency-controlled visibility)
- Money flows: Lender → Agency → Borrower, then Borrower → Agency → Lender
- The markup (spread between base rate and final rate) is the agency's revenue

## Roles

| Role | Scope | Purpose |
|---|---|---|
| Super Admin | Platform | Creates/manages agencies, platform-wide dashboard |
| Agency Admin | Tenant | Sets markup caps, lender visibility, manages agents, agency dashboard |
| Agency Manager | Tenant | Oversees agents and portfolio, team dashboard |
| Agent | Tenant | Onboards lenders, registers borrowers, sets markup, personal dashboard |
| Lender User | Tenant | Defines products, approves/rejects, sees only allowed data, lender dashboard |
| Borrower | Tenant | Views offers, accepts, repays, personal dashboard |

## Database Architecture

Single PostgreSQL database. All tables live in `database/migrations/`.

### Central tables (no tenant_id — shared infrastructure)

- **`tenants`** — id (string PK), company_name, registration_number, country_code (char 2 default 'GB'), contact_email, contact_phone, settings (jsonb), subscription_plan (enum starter/professional/enterprise default 'starter'), status (enum active/suspended/cancelled default 'active'), data (jsonb — Stancl internal), timestamps

- **`domains`** — id, domain (unique), tenant_id (FK cascade), timestamps (from starter kit)

- **`users`** — extended from starter kit:
  - id, name, email (unique), password, timestamps (from starter kit)
  - `tenant_id` UUID nullable (null = super admin)
  - `role` enum: super_admin/agency_admin/agency_manager/agent/lender/borrower
  - `agent_id` UUID nullable (links to agents.id)
  - `borrower_id` UUID nullable (links to borrowers.id)
  - `lender_id` UUID nullable (links to lenders.id)

- **`roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `model_has_permissions`** — from starter kit

### Business tables (ALL have `tenant_id` UUID, FK to tenants.id, cascade on delete, indexed)

**Every business table MUST have:**
- `id` UUID PK (via `HasUuids`)
- `tenant_id` UUID (FK → tenants.id, `cascadeOnDelete`, indexed)
- `timestamps()`

**Compound unique constraints must include `tenant_id`:**
- e.g. `unique(['tenant_id', 'slug'])`, `unique(['tenant_id', 'email'])` — never just `slug` alone

The 14 business tables:

1. **`lenders`**
   - id, tenant_id, name, slug, contact_email, contact_phone, address, country_code, metadata (jsonb), status (enum active/inactive/suspended), onboarded_by_agent_id (UUID nullable), timestamps
   - UNIQUE (tenant_id, slug)

2. **`lender_visibility_settings`**
   - id, tenant_id, lender_id (FK cascade, UNIQUE with tenant_id)
   - `show_borrower_name` BOOLEAN default true
   - `show_borrower_contact` BOOLEAN default false
   - `show_borrower_address` BOOLEAN default false
   - `show_borrower_employment` BOOLEAN default true
   - `show_borrower_income` BOOLEAN default true
   - `show_borrower_risk_tier` BOOLEAN default true
   - `show_borrower_national_id` BOOLEAN default false
   - `show_borrower_documents` BOOLEAN default true
   - `allowed_document_types` JSONB nullable
   - `show_final_rate` BOOLEAN default false
   - `show_markup` BOOLEAN default false
   - `show_competing_lenders` BOOLEAN default false
   - `show_agency_notes` BOOLEAN default false
   - `custom_rules` JSONB nullable
   - `updated_by` UUID FK users
   - timestamps

3. **`loan_products`**
   - id, tenant_id, lender_id (FK cascade), name, description (text), min_amount (decimal 15,2), max_amount (decimal 15,2), min_tenure_months (int), max_tenure_months (int), base_rate (decimal 6,3), currency (char 3 default 'GBP'), eligibility_rules (jsonb), required_documents (jsonb), approval_rules (jsonb), status (enum active/inactive/archived), timestamps

4. **`agents`**
   - id, tenant_id, user_id (FK cascade), employee_code, first_name, last_name, email, phone, date_of_birth (date), national_id, tier (enum junior/senior/principal), commission_share_pct (decimal 5,2 default 50.00), status (enum active/inactive/suspended), timestamps
   - UNIQUE (tenant_id, employee_code), UNIQUE (tenant_id, email)

5. **`borrowers`**
   - id, tenant_id, user_id (FK nullable), first_name, last_name, email, phone, date_of_birth (date), national_id, employment_status, employer_name, annual_income (decimal 15,2), risk_tier, kyc_status (enum pending/verified/rejected), registered_by_agent_id, metadata (jsonb), timestamps

6. **`applications`**
   - id, tenant_id, application_no, borrower_id (FK cascade), agent_id (FK cascade), requested_amount (decimal 15,2), requested_tenure_months (int), purpose (text), status (enum draft/submitted/under_review/approved/rejected/disbursed/closed), submitted_at, timestamps
   - UNIQUE (tenant_id, application_no)

7. **`application_documents`**
   - id, tenant_id, application_id (FK cascade), doc_type, file_url, uploaded_by (FK users nullable), verified (bool), verified_at, timestamps

8. **`offers`** — CORE MODEL
   - id, tenant_id, offer_no, application_id (FK cascade), lender_id (FK), loan_product_id (FK)
   - principal_amount, tenure_months, lender_base_rate (decimal 6,3)
   - markup_type (enum percentage/flat_fee/combined/none), markup_percentage (decimal 6,3 default 0), markup_flat_fee (decimal 15,2 default 0), agent_notes (text nullable)
   - Guardrail snapshot: max_percentage_allowed (decimal 6,3 nullable), max_flat_fee_allowed (decimal 15,2 nullable)
   - Computed: final_rate (decimal 6,3), final_fee (decimal 15,2 default 0), apr (decimal 6,3 nullable), monthly_payment (decimal 15,2), total_repayable (decimal 15,2), total_lender_due (decimal 15,2), total_markup_earned (decimal 15,2)
   - status (enum draft/sent/viewed/accepted/rejected/expired), sent_at, responded_at, expires_at, created_by (FK agents), timestamps
   - UNIQUE (tenant_id, offer_no)

9. **`loans`**
   - id, tenant_id, loan_no, offer_id (FK), application_id (FK), borrower_id (FK), lender_id (FK), agent_id (FK)
   - principal_amount, lender_base_rate, final_rate, markup_percentage, markup_flat_fee, monthly_payment, total_repayable, total_lender_due, total_markup_earned
   - status (enum pending_lender/approved/rejected/disbursed/active/repaid/defaulted/written_off), lender_approved_at, disbursed_at, closed_at, timestamps
   - UNIQUE (tenant_id, loan_no)

10. **`loan_ledger`**
    - id, tenant_id, loan_id (FK cascade), entry_type (enum disbursement/borrower_payment/lender_payout/agency_revenue/agent_commission/adjustment), counterparty (enum lender/borrower/agency/agent), amount (decimal 15,2), reference, metadata (jsonb), recorded_at, timestamps

11. **`repayment_schedule`**
    - id, tenant_id, loan_id (FK cascade), installment_no (int), due_date (date), principal_due, interest_due, fee_due (default 0), total_due, status (enum pending/paid/overdue/waived), paid_at, timestamps

12. **`markup_limits`**
    - id, tenant_id, scope_type (enum agency/lender/product), scope_id (UUID nullable), allow_percentage (bool default true), allow_flat_fee (bool default true), allow_combined (bool default false), max_percentage (decimal 6,3 nullable), max_flat_fee (decimal 15,2 nullable), created_by (FK users), timestamps

13. **`commissions`**
    - id, tenant_id, loan_id (FK cascade), agent_id (FK), total_markup, agent_share, agency_share, status (enum pending/payable/paid), payable_at, paid_at, timestamps

14. **`audit_logs`**
    - id bigserial PK, tenant_id UUID, entity_type (varchar 50 — 'offer' | 'lender_visibility' | 'markup_limit'), entity_id UUID, action (varchar 50), field_changed (varchar 100 nullable), old_value (text nullable), new_value (text nullable), reason (text nullable), changed_by (UUID FK users), changed_at (timestamp default now()), timestamps

## Tenancy Trait

Create `app/Models/Concerns/BelongsToTenant.php`:

```php
<?php

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
                $query->where(
                    $query->getModel()->getTable() . '.tenant_id',
                    tenant()->id
                );
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
```

**Every business model uses `use HasUuids, BelongsToTenant;`** — no exceptions.

## Models

Create 14 models in `app/Models/`. All use `HasUuids` and `BelongsToTenant`.

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Lender extends Model
{
    use HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'contact_email', 'contact_phone',
        'address', 'country_code', 'metadata', 'status',
        'onboarded_by_agent_id',
    ];

    protected $casts = ['metadata' => 'array'];

    public function loanProducts()    { return $this->hasMany(LoanProduct::class); }
    public function visibility()      { return $this->hasOne(LenderVisibilitySetting::class); }
    public function onboardedBy()     { return $this->belongsTo(Agent::class, 'onboarded_by_agent_id'); }
    public function loans()           { return $this->hasMany(Loan::class); }
}
```

Models to create: `Lender`, `LenderVisibilitySetting`, `LoanProduct`, `Agent`, `Borrower`, `Application`, `ApplicationDocument`, `Offer`, `Loan`, `LoanLedger` (table: `loan_ledger`), `RepaymentSchedule` (table: `repayment_schedule`), `MarkupLimit`, `Commission`, `AuditLog`.

Update the existing `User` model to add: `tenant_id`, `role`, `agent_id`, `borrower_id`, `lender_id`.

## Services

### `app/Services/MarkupService.php`

**`resolveGuardrails(?string $lenderId, ?string $productId): array`**
Query `markup_limits` matching scope agency OR lender OR product. Return:
- allow_percentage / allow_flat_fee / allow_combined = every matching limit allows it
- max_percentage = MIN across matching limits (or 0)
- max_flat_fee = MIN across matching limits (or 0)
- guardrail_ids = array of matching ids

If no limits: all flags false, max values 0.

**`validateMarkup(array $guardrails, string $type, float $pct, float $flat): void`**
Throw `InvalidArgumentException` for negative values, disallowed types, or values exceeding the max.

**`computeOffer(float $principal, int $months, float $baseRate, string $type, float $pct, float $flat): array`**
Return final_rate, final_fee, monthly_payment, total_repayable, total_lender_due, total_markup_earned.

**`amortizedPayment(float $principal, float $annualRate, int $months): float`**
Formula: `r = annualRate/100/12`; if r=0 return principal/months; else `principal*r/(1-(1+r)^-months)`.

### `app/Services/LoanProvisioningService.php`

**`provisionFromOffer(Offer $offer): Loan`** — transaction:
1. Create Loan (snapshot offer values, status='pending_lender')
2. Generate repayment schedule (amortize, first due +1 month)
3. Create Commission (split total_markup_earned by agent.commission_share_pct)

**`markDisbursed(Loan $loan): void`** — transaction: update loan, insert ledger entry (disbursement).

### `app/Services/LoanLedgerService.php`

**`recordPayment(Loan, RepaymentSchedule, float): void`** — transaction: insert 3 ledger rows (borrower_payment, lender_payout, agency_revenue), update installment to paid, close loan if all paid.

**`getLoanSummary(Loan): array`** — sums per entry_type.

### `app/Services/LenderMatchService.php`

**`getEligibleProducts(float $amount, int $months): Collection`** — filter active products by amount/tenure ranges, eager-load lender.

### `app/Services/LenderVisibilityService.php`

**`forLender(Lender $lender): array`** — return effective rules; create defaults if missing.

**`canSee(Lender $lender, string $field): bool`** — check a specific field.

**`filterDocuments(Lender $lender, Collection $documents): Collection`** — filter by `allowed_document_types` (null = all).

**`updateSettings(Lender $lender, array $data): LenderVisibilitySetting`** — update + audit via AuditService.

### `app/Services/AuditService.php`

**`log(string $entityType, string $entityId, string $action, ?string $field, $old, $new, ?string $reason): void`**
Insert into `audit_logs`. Auto-fills tenant_id and changed_by.

## API Resources — Privacy Layer

### `app/Http/Resources/Borrower/BorrowerOfferResource.php`
Return: offer_no, lender_name, principal_amount, tenure_months, final_rate, final_fee, apr, monthly_payment, total_repayable, status, expires_at.
NEVER: lender_base_rate, markup_*, total_markup_earned.

### `app/Http/Resources/Lender/LenderApplicationResource.php`
**Must respect `LenderVisibilityService`.** Read the settings, then conditionally include fields:

```php
$lender = auth()->user()->lender;
$v = app(LenderVisibilityService::class)->forLender($lender);
$offer = $this->offers->first();
$b = $this->borrower;

$profile = [];
if ($v['show_borrower_name'])        $profile['first_name'] = $b->first_name;
if ($v['show_borrower_name'])        $profile['last_name']  = $b->last_name;
if ($v['show_borrower_contact'])     $profile['email']      = $b->email;
if ($v['show_borrower_contact'])     $profile['phone']      = $b->phone;
if ($v['show_borrower_employment'])  $profile['employment_status'] = $b->employment_status;
if ($v['show_borrower_employment'])  $profile['employer_name']     = $b->employer_name;
if ($v['show_borrower_income'])      $profile['annual_income'] = (float) $b->annual_income;
if ($v['show_borrower_risk_tier'])   $profile['risk_tier']     = $b->risk_tier;
if ($v['show_borrower_national_id']) $profile['national_id']   = $b->national_id;

$documents = [];
if ($v['show_borrower_documents']) {
    $docs = $v['allowed_document_types'] === null
        ? $this->documents
        : $this->documents->whereIn('doc_type', $v['allowed_document_types']);
    $documents = $docs->map(fn($d) => ['doc_type' => $d->doc_type, 'file_url' => $d->file_url])->values()->toArray();
}

$payload = [
    'application_no'   => $this->application_no,
    'requested_amount' => (float) $this->requested_amount,
    'tenure_months'    => $this->requested_tenure_months,
    'purpose'          => $this->purpose,
    'status'           => $this->status,
    'borrower_profile' => $profile,
    'documents'        => $documents,
    'my_base_rate'     => $offer ? (float) $offer->lender_base_rate : null,
];

if ($v['show_final_rate'])        $payload['final_rate'] = (float) $offer->final_rate;
if ($v['show_markup']) {
    $payload['markup_type']       = $offer->markup_type;
    $payload['markup_percentage'] = (float) $offer->markup_percentage;
    $payload['markup_flat_fee']   = (float) $offer->markup_flat_fee;
}
if ($v['show_agency_notes'])      $payload['agency_notes'] = $offer->agent_notes;
if ($v['show_competing_lenders']) {
    $payload['competing_lenders'] = $this->offers()
        ->where('lender_id', '!=', $offer->lender_id)
        ->with('lender:id,name')
        ->get()->pluck('lender.name')->toArray();
}

return $payload;
```

### `app/Http/Resources/Agent/AgentOfferResource.php`
Return everything (agent is trusted).

## Controllers

### Central — `app/Http/Controllers/Central/`
- `TenantController.php` — index, store (insert tenant row + domain only, no DB creation), show, update, destroy
- `DashboardController.php` — platform metrics

### Tenant — `app/Http/Controllers/Tenant/`
- `LenderController.php` — index, store, show, update
- `LenderVisibilityController.php` — show, update
- `LoanProductController.php` — index, store, update
- `AgentController.php` — CRUD
- `BorrowerController.php` — CRUD
- `ApplicationController.php` — index, store, show, eligibleProducts, submit
- `OfferController.php` — index, store, show, update, send, accept, submitToLender
- `MarkupLimitController.php` — index, store, update, destroy
- `LoanController.php` — index, show, storeFromOffer, disburse
- `CommissionController.php` — index
- `DashboardController.php` — agency admin dashboard
- `ReportController.php` — markupOutliers, agentPerformance, lenderPerformance

**OfferController@store** flow:
1. Validate request
2. Load product
3. `$guardrails = $markupService->resolveGuardrails($lender_id, $loan_product_id)`
4. `$markupService->validateMarkup(...)` — throws on failure
5. `$computed = $markupService->computeOffer(...)`
6. Create Offer with snapshot + computed values, created_by = agent_id
7. `AuditService::log('offer', $offer->id, 'created', null, null, json_encode($request->all()))`
8. Return `AgentOfferResource`

**OfferController@update** — only if status='draft'. Revalidate + recompute. Log to audit_logs with action='markup_changed' and old/new values.

### Borrower-facing — `app/Http/Controllers/Api/Borrower/`
- `OfferController.php` — index, show, accept, reject (verify ownership on every action)
- `DashboardController.php` — borrower dashboard

### Lender-facing — `app/Http/Controllers/Api/Lender/`
- `ApplicationController.php` — index, approve, reject
- `DashboardController.php` — lender dashboard

### Agent-facing — `app/Http/Controllers/Api/Agent/`
- `DashboardController.php` — agent dashboard

## Dashboards

Dashboard services live in `app/Services/Dashboards/`. Controllers are thin — just call the service and return JSON.

### `SuperAdminDashboardService.php`
- totals.agencies, totals.active_agencies, totals.total_users
- totals.total_loans_originated, totals.total_volume, totals.total_revenue
- top_agencies_by_volume (top 10)
- recent_agencies (last 5)
- growth.monthly_originations (12 months)
- system_health (per-tenant record counts)

### `AgencyAdminDashboardService.php`
- portfolio.total_loans, active_loans, total_principal, total_revenue, pending_revenue
- pipeline — applications grouped by status
- offers.by_status
- recent_offers (last 10)
- agent_leaderboard (top 10 by loans_count + revenue)
- lender_performance — per lender: applications, approved, rejected, approval_rate, volume, avg_rate
- markup_analysis.distribution (histogram of markup buckets), avg_markup, outliers (agents >1.5× avg)
- compliance.alerts — at-ceiling count, offers changed after sent, visibility changes last 30 days
- monthly_trend (12 months)

### `AgencyManagerDashboardService.php`
- team.my_agents, team.pipeline, team.this_month, recent_activity (20)

### `AgentDashboardService.php`
- my_pipeline.applications, offers_sent, offers_accepted, conversion_rate
- my_loans.active, total_volume
- my_earnings.pending, payable, paid_this_year, lifetime
- my_lenders (onboarded by me)
- recent_offers (last 10)
- monthly_trend (6 months)

### `LenderDashboardService.php`
- my_products
- applications.pending, this_month, approval_rate
- my_portfolio.active_loans, total_principal, total_disbursed, repaid
- recent_applications (last 10, respecting visibility)
- monthly_trend (12 months)

### `BorrowerDashboardService.php`
- my_applications
- active_offers
- my_loans.active, total_outstanding, next_payment
- repayment_schedule (next 5)
- payment_history (last 10)
- recent_activity (5)

## Routes

```php
// Central (routes/web.php)
Route::middleware(['web', 'central', 'role:super_admin'])->prefix('admin')->group(function () {
    Route::resource('tenants', Central\TenantController::class);
    Route::get('dashboard', [Central\DashboardController::class, 'index']);
});

// Tenant (routes/tenant.php)
Route::middleware(['web', 'tenant'])->group(function () {

    // Agency admin + manager
    Route::middleware('role:agency_admin,agency_manager')->group(function () {
        Route::get('dashboard', [Tenant\DashboardController::class, 'index']);
        Route::get('reports/markup-outliers',    [Tenant\ReportController::class, 'markupOutliers']);
        Route::get('reports/agent-performance',  [Tenant\ReportController::class, 'agentPerformance']);
        Route::get('reports/lender-performance', [Tenant\ReportController::class, 'lenderPerformance']);
    });

    // Agency admin only
    Route::middleware('role:agency_admin')->group(function () {
        Route::get('lenders/{lender}/visibility',   [Tenant\LenderVisibilityController::class, 'show']);
        Route::patch('lenders/{lender}/visibility', [Tenant\LenderVisibilityController::class, 'update']);
        Route::resource('markup-limits', Tenant\MarkupLimitController::class);
    });

    // Shared agency operations (admin + manager + agent)
    Route::middleware('role:agency_admin,agency_manager,agent')->group(function () {
        Route::resource('lenders', Tenant\LenderController::class);
        Route::resource('lenders.products', Tenant\LoanProductController::class);
        Route::resource('borrowers', Tenant\BorrowerController::class);
        Route::resource('applications', Tenant\ApplicationController::class);

        Route::get('applications/{application}/eligible-products', [Tenant\ApplicationController::class, 'eligibleProducts']);
        Route::post('applications/{application}/submit',           [Tenant\ApplicationController::class, 'submit']);

        Route::get('applications/{application}/offers', [Tenant\OfferController::class, 'index']);
        Route::post('applications/{application}/offers',[Tenant\OfferController::class, 'store']);
        Route::get('offers/{offer}',    [Tenant\OfferController::class, 'show']);
        Route::patch('offers/{offer}',  [Tenant\OfferController::class, 'update']);
        Route::post('offers/{offer}/send',             [Tenant\OfferController::class, 'send']);
        Route::post('offers/{offer}/accept',           [Tenant\OfferController::class, 'accept']);
        Route::post('offers/{offer}/submit-to-lender', [Tenant\OfferController::class, 'submitToLender']);

        Route::resource('loans', Tenant\LoanController::class);
        Route::post('offers/{offer}/create-loan', [Tenant\LoanController::class, 'storeFromOffer']);
        Route::post('loans/{loan}/disburse',      [Tenant\LoanController::class, 'disburse']);
        Route::get('commissions', [Tenant\CommissionController::class, 'index']);
    });

    // Agent
    Route::middleware('role:agent')->prefix('agent')->group(function () {
        Route::get('dashboard', [Api\Agent\DashboardController::class, 'index']);
    });

    // Borrower
    Route::middleware('role:borrower')->prefix('my')->group(function () {
        Route::get('dashboard',              [Api\Borrower\DashboardController::class, 'index']);
        Route::get('offers',                 [Api\Borrower\OfferController::class, 'index']);
        Route::get('offers/{offer}',         [Api\Borrower\OfferController::class, 'show']);
        Route::post('offers/{offer}/accept', [Api\Borrower\OfferController::class, 'accept']);
        Route::post('offers/{offer}/reject', [Api\Borrower\OfferController::class, 'reject']);
    });

    // Lender
    Route::middleware('role:lender')->prefix('lender')->group(function () {
        Route::get('dashboard', [Api\Lender\DashboardController::class, 'index']);
        Route::get('applications', [Api\Lender\ApplicationController::class, 'index']);
        Route::post('applications/{application}/approve', [Api\Lender\ApplicationController::class, 'approve']);
        Route::post('applications/{application}/reject',  [Api\Lender\ApplicationController::class, 'reject']);
    });
});
```

## Tests

### `tests/Unit/Services/MarkupServiceTest.php`
- Percentage markup computed correctly
- Flat fee markup computed correctly
- Above-max throws
- Disallowed type throws
- Negative throws

### `tests/Unit/Services/LenderVisibilityServiceTest.php`
- `forLender` returns defaults when no settings exist
- `forLender` returns saved settings when they exist
- `canSee` returns correct boolean
- `filterDocuments` filters when `allowed_document_types` set
- `filterDocuments` returns all when null

### `tests/Feature/Privacy/BorrowerPrivacyTest.php`
Borrower JSON has no markup fields.

### `tests/Feature/Privacy/LenderVisibilityTest.php`
- `show_borrower_name=true` → sees name
- `show_borrower_name=false` → no name
- `show_final_rate=false` → no final_rate
- `show_markup=true` → sees markup fields
- `allowed_document_types=['pan']` → only PAN docs

### `tests/Feature/Services/LoanLedgerServiceTest.php`
Payment creates exactly 3 ledger entries.

### `tests/Feature/Dashboards/DashboardAccessTest.php`
- Super admin → `/admin/dashboard` OK
- Agency admin → `/dashboard` OK
- Agent → `/agent/dashboard` OK
- Borrower → `/my/dashboard` OK
- Lender → `/lender/dashboard` OK
- Agent → `/dashboard` returns 403
- Borrower → `/lender/dashboard` returns 403

### `tests/Feature/Tenancy/TenantIsolationTest.php`
- Create Tenant A and Tenant B
- Boot Tenant A context, create a Lender
- Boot Tenant B context, query `Lender::all()` → assert EMPTY
- Boot Tenant A → assert 1
- Confirm `Lender::withoutGlobalScope('tenant')->count()` returns 1 (proving the data exists but is filtered)

### `tests/Feature/Tenancy/TenantScopeBypassGuardTest.php`
- Scan codebase for `withoutGlobalScope`, `DB::table`, and raw SQL in `app/` — assert none of them touch tenant-scoped tables outside of migrations/seeders. This is a static guard against accidental leakage.

## Critical Implementation Rules

1. **NEVER put markup validation in the frontend or FormRequests.** Only in `MarkupService`.
2. **Snapshot guardrails on the offer.** Admin changes to `markup_limits` must not affect existing offers.
3. **Log every markup change** to `audit_logs` (entity_type='offer').
4. **Log every lender visibility change** to `audit_logs` (entity_type='lender_visibility').
5. **Never return raw `Offer` model** from a controller. Always use role-specific Resources.
6. **`LenderApplicationResource` must respect `LenderVisibilityService`** — no shortcuts.
7. **Wrap in DB transactions**: `provisionFromOffer`, `markDisbursed`, `recordPayment`.
8. **UUIDs** as primary keys. `audit_logs` uses bigserial.
9. **Money**: `decimal(15,2)`. **Rates**: `decimal(6,3)`.
10. **Every business table has `tenant_id` + index. Every business model uses `BelongsToTenant`.**
11. **Unique constraints must include `tenant_id`.**
12. **Never use `withoutGlobalScope('tenant')`** except in tests or admin aggregate queries — and when you do, log it in a comment.
13. **Avoid raw `DB::table()` queries on business tables** — use Eloquent so the tenant scope applies.
14. **Dashboard services** live in `app/Services/Dashboards/`. Controllers stay thin.
15. **Follow the starter kit's namespace conventions.**

## Acceptance Criteria

- [ ] `php artisan migrate` runs cleanly (all 14 tables created in one DB)
- [ ] Subdomain routing works (`acme.localhost` → Tenant `acme`)
- [ ] Create a lender as Tenant A → query as Tenant B returns empty
- [ ] `withoutGlobalScope('tenant')` on the same query returns the row (proves scope works)
- [ ] Creating an offer above guardrail throws
- [ ] Creating a valid offer computes `final_rate` correctly and snapshots guardrails
- [ ] Editing a draft offer logs to `audit_logs`
- [ ] Borrower endpoint returns NO markup fields
- [ ] Lender endpoint respects visibility settings — toggle `show_final_rate=true` and verify it appears
- [ ] Lender endpoint with `show_borrower_name=false` does NOT include name
- [ ] Accepting an offer + create-loan produces 1 Loan + N RepaymentSchedule + 1 Commission
- [ ] Disbursement inserts 1 ledger entry
- [ ] Payment inserts 3 ledger entries
- [ ] Every role can access their own dashboard
- [ ] Cross-role dashboard access returns 403
- [ ] All 8 test suites pass: `php artisan test`

## What to Ask Me Before Starting

1. Show me the starter kit's `config/tenancy.php` — confirm which bootstrappers to keep/remove.
2. Show me how middleware is registered in the starter kit.
3. Show me the existing `User` model and `tenants` migration.
4. Show me the starter kit's role-check convention (Spatie Permissions? custom middleware?).
5. Confirm: PostgreSQL version?
6. Confirm: should `SuperAdminDashboardService` aggregate across all tenants by using `withoutGlobalScope('tenant')` where needed? (Yes is assumed — but confirm.)

## What to Do After Each Phase

Pause and show:
- Files created/modified
- The Artisan command you ran
- The output

Wait for confirmation before the next phase.

## Phases

**Phase 1** — Convert tenancy to single-DB (edit `config/tenancy.php`, create `BelongsToTenant` trait)
**Phase 2** — Central migrations (`tenants` extensions, `users` extensions)
**Phase 3** — Tenant migrations (14 files, all in `database/migrations/`)
**Phase 4** — Tenant models (14 files in `app/Models/`)
**Phase 5** — Services (MarkupService, LoanProvisioningService, LoanLedgerService, LenderMatchService, LenderVisibilityService, AuditService)
**Phase 6** — Controllers + API Resources + Routes
**Phase 7** — Dashboard services + controllers + endpoints
**Phase 8** — Tests (8 suites)
**Phase 9** — Demo seeder (one tenant with lenders, agents, borrowers, offers)