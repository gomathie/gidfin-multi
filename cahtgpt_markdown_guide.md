# ROLE

You are my senior Laravel software architect and implementation agent.

You are working with two existing repositories:

1. A Laravel multi-tenant SaaS starter kit.
2. LAMaS, an existing loan-management application.

Your task is to build a production-quality multi-tenant loan-agency / loan-brokerage SaaS platform. PWA compactible.

The SaaS starter is the PRIMARY application.

LAMaS is a READ-ONLY domain reference.

The database is PostgreSQL.

The frontend must use:

- Vue 3
- Inertia.js
- TypeScript
- Tailwind CSS
- Vite

The architecture must use a SINGLE PostgreSQL DATABASE.

Do not create one database per tenant.

Tenant isolation must be implemented at the row level using `tenant_id`, authorization, query scoping, database constraints, and comprehensive automated tests.

---

# PRODUCT OBJECTIVE

The system is a SaaS platform for loan agencies and independent loan agents.

Each agency or independent agent is a tenant.

Each tenant manages:

- internal users;
- agents;
- lenders;
- lender users;
- lender loan products;
- borrowers;
- borrower documents;
- loan applications;
- lender submissions;
- lender quotes;
- agency markup;
- customer offers;
- loans;
- loan ledger;
- commissions;
- audit history.

The agency/agent acts as an intermediary between borrowers and multiple lenders.

The business flow is:

```text
Borrower
    ↓
Agency / Agent
    ↓
Loan Application
    ↓
Multiple Lenders
    ↓
Lender Loan Products
    ↓
Eligibility Matching
    ↓
Lender Quote
    ↓
Agency Markup
    ↓
Customer Offer
    ↓
Customer Selection
    ↓
Lender Decision / Acceptance
    ↓
Loan
    ↓
Disbursement
    ↓
Loan Ledger
    ↓
Agency / Agent Commission
```

A lender provides its own base interest rate.

The agency/agent may add a permitted markup.

The platform must always preserve these values independently:

```text
lender_base_rate
agency_markup
customer_rate
```

For example:

```text
Lender Base Rate: 12.00%
Agency Markup:      3.00%
Customer Rate:     15.00%
```

Never overwrite the lender's rate with the customer rate.

---

# OUT OF SCOPE FOR INITIAL IMPLEMENTATION

Do NOT build the following yet:

- AI chatbot;
- AI underwriting;
- AI lender recommendation;
- OCR;
- automatic ID extraction;
- automatic bank statement analysis;
- Open Banking;
- credit bureau automation;
- automated repayments;
- direct debit;
- payment gateway;
- collections automation;
- debt recovery automation;
- predictive credit scoring;
- advanced analytics;
- complex accounting integrations;
- lender API automation unless specifically required later.

The first objective is to create the correct brokerage domain.

---

# SOURCE CODE STRATEGY

## SaaS Starter

The SaaS starter owns:

- Laravel application infrastructure;
- authentication;
- tenancy;
- tenant resolution;
- platform administration;
- Vue/Inertia shell;
- routes;
- middleware;
- authorization foundation;
- queues;
- caching;
- storage;
- notifications;
- deployment structure.

Preserve working infrastructure.

Do not rewrite working SaaS starter functionality unnecessarily.

## LAMaS

LAMaS is used only as a source of domain knowledge.

Study its:

- Customer model;
- LoanApplication model;
- Broker model;
- Promoter model;
- CreditRisk model;
- Portfolio concepts;
- migrations;
- validation;
- loan-related relationships;
- business rules;
- calculations;
- workflows;
- enums/status logic;
- tests.

Do NOT blindly copy:

- authentication;
- users;
- Jetstream;
- Livewire;
- AdminLTE;
- navigation;
- routes;
- controllers;
- tenant/company assumptions;
- migrations;
- frontend.

Extract useful loan-domain concepts and implement them cleanly in the SaaS starter.

---

# REQUIRED WORKING METHOD

Before writing feature code:

1. Inspect the entire SaaS starter.
2. Inspect the entire LAMaS project.
3. Read both `composer.json` files.
4. Inspect database migrations.
5. Inspect models.
6. Inspect middleware.
7. Inspect routes.
8. Inspect authentication.
9. Inspect authorization.
10. Inspect tenant-resolution logic.
11. Inspect queue configuration.
12. Inspect storage configuration.
13. Inspect existing Vue/React frontend architecture.
14. Inspect tests.
15. Identify package/version incompatibilities.
16. Identify existing models that can be reused.
17. Identify concepts that must be replaced.
18. Identify domain logic worth transplanting from LAMaS.

Do not start coding until this audit is complete.

---

# REQUIRED PHASE 0 DOCUMENTATION

Create:

```text
docs/architecture/CURRENT_SAAS_ARCHITECTURE.md

docs/architecture/LAMAS_DOMAIN_AUDIT.md

docs/architecture/LAMAS_MODEL_MAP.md

docs/architecture/LAMAS_MIGRATION_MAP.md

docs/architecture/TARGET_DOMAIN_MODEL.md

docs/architecture/TARGET_ERD.md

docs/architecture/TENANT_ISOLATION_STRATEGY.md

docs/architecture/RBAC_MATRIX.md

docs/architecture/APPLICATION_STATE_MACHINE.md

docs/architecture/PRICING_MODEL.md

docs/architecture/LOAN_LEDGER_DESIGN.md

docs/architecture/TRANSPLANT_PLAN.md

docs/architecture/IMPLEMENTATION_PHASES.md
```

These documents must describe actual repository findings.

Do not write hypothetical documentation and then ignore the existing application.

---

# SINGLE DATABASE ARCHITECTURE

Use one PostgreSQL database.

The platform uses shared tables.

All tenant-owned operational data must carry a `tenant_id`.

Conceptually:

```text
┌───────────────────────────────────────────────────────┐
│                POSTGRESQL DATABASE                    │
│                                                       │
│ Platform Tables                                       │
│ ├── tenants                                           │
│ ├── domains                                           │
│ ├── central_users                                     │
│ ├── platform_settings                                 │
│ └── platform_audit_logs                               │
│                                                       │
│ Tenant-Owned Tables                                   │
│ ├── users                                             │
│ ├── agents                                            │
│ ├── lenders                                           │
│ ├── lender_users                                      │
│ ├── loan_products                                     │
│ ├── borrowers                                         │
│ ├── borrower_documents                                │
│ ├── applications                                      │
│ ├── lender_matches                                    │
│ ├── lender_submissions                                │
│ ├── lender_quotes                                     │
│ ├── markup_limits                                     │
│ ├── markup_rules                                      │
│ ├── pricing_overrides                                 │
│ ├── offers                                            │
│ ├── loans                                             │
│ ├── loan_ledger                                       │
│ ├── commission_rules                                  │
│ ├── commissions                                       │
│ └── audit_events                                      │
│                                                       │
└───────────────────────────────────────────────────────┘
```

Every tenant-owned table must include:

```text
tenant_id
```

unless there is a documented reason not to.

---

# TENANT

A tenant represents:

```text
AGENCY
```

or:

```text
INDEPENDENT_AGENT
```

Suggested `tenants` fields:

```text
id
uuid
name
tenant_type

legal_name
trading_name

registration_number
regulatory_reference

country
currency
timezone

status

contact_email
contact_phone

created_at
updated_at
```

`tenant_type`:

```text
AGENCY
INDEPENDENT_AGENT
```

Tenant status:

```text
PENDING
ACTIVE
SUSPENDED
INACTIVE
```

Use the SaaS starter's existing tenant model if appropriate.

Do not create another Agency model if Tenant already represents the agency.

---

# DOMAINS

Keep the starter's existing domain mapping unless it is technically unsuitable.

Examples:

```text
agency-a.example.com

agency-b.example.com
```

Tenant resolution should happen before tenant-owned business queries run.

---

# CENTRAL USERS

Platform administration must remain separate from tenant users.

Central roles:

```text
PLATFORM_SUPER_ADMIN
PLATFORM_ADMIN
PLATFORM_SUPPORT
PLATFORM_AUDITOR
```

Central users must not accidentally become ordinary tenant users.

---

# TENANT ISOLATION

This is one of the most important requirements in the application.

A Tenant A user must NEVER see Tenant B data.

Do not rely only on frontend filtering.

Implement multiple layers of protection.

## Required tenant isolation layers

### 1. Tenant Context

Create or reuse a tenant-context service/middleware.

Every tenant request must resolve:

```text
currentTenant()
```

The tenant context must be established before business logic executes.

### 2. Tenant-Owned Models

Tenant-owned Eloquent models should use a common concern such as:

```text
BelongsToTenant
```

or equivalent.

The trait should:

- automatically populate `tenant_id` on creation;
- scope queries to the current tenant;
- prevent accidental cross-tenant creation;
- provide safe tenant relationships.

Do not blindly implement a global scope without considering queues, platform jobs, CLI, testing, and administration.

Provide an explicit safe mechanism for platform/system processes that legitimately need unscoped access.

### 3. Laravel Policies

Authorization must confirm both:

```text
user permission
AND
resource tenant ownership
```

### 4. Database Constraints

Use foreign keys and tenant-aware unique constraints.

### 5. Automated Tests

Cross-tenant access must be covered thoroughly.

---

# POSTGRESQL ROW LEVEL SECURITY

Evaluate PostgreSQL Row Level Security as an additional defense-in-depth layer.

Do NOT enable it blindly before understanding the application's connection pooling and tenant-context implementation.

If implemented, document:

- how `app.current_tenant_id` or equivalent is set;
- how queues set tenant context;
- how platform administration bypasses RLS securely;
- how migrations work;
- how automated tests verify policies.

Application-level tenant scoping remains mandatory even if RLS is added.

---

# TENANT-AWARE UNIQUE CONSTRAINTS

Do not create globally unique values when uniqueness is only required within a tenant.

For example:

```text
UNIQUE (tenant_id, borrower_number)

UNIQUE (tenant_id, lender_code)

UNIQUE (tenant_id, loan_product_code)

UNIQUE (tenant_id, application_reference)

UNIQUE (tenant_id, loan_reference)
```

Do not rely only on Laravel validation.

Back these constraints with PostgreSQL unique indexes.

---

# TENANT USERS

Tenant users live in the shared `users` table.

Every tenant user must have:

```text
tenant_id
```

Roles include agency-side and lender-side users.

Agency-side roles:

```text
AGENCY_OWNER
AGENCY_ADMIN
MANAGER
AGENT
COMPLIANCE
AUDITOR
```

Lender-side roles:

```text
LENDER_ADMIN
LENDER_MANAGER
LOAN_OFFICER
UNDERWRITER
```

Review the starter's role implementation.

If necessary, introduce Spatie Laravel Permission, but only if it fits cleanly with the SaaS starter.

Avoid maintaining two authorization systems.

---

# TENANT USER UNIQUENESS

Decide intentionally whether email should be:

```text
globally unique
```

or:

```text
unique per tenant
```

Preferred SaaS behavior may be:

```text
UNIQUE (tenant_id, email)
```

if the same person could have separate accounts in multiple agencies.

If global identity is desired instead, document the reason and design membership tables appropriately.

Do not make this decision accidentally.

---

# AGENTS

Study the LAMaS `Promoter` concept.

Map useful fields and behavior into:

```text
agents
```

Suggested fields:

```text
id
uuid
tenant_id

user_id

agent_code

manager_id nullable
branch_id nullable

status

commission_rule_id nullable

created_at
updated_at
```

If tenant type is:

```text
INDEPENDENT_AGENT
```

the owner can also have an associated Agent profile.

---

# LENDERS

Lenders belong to the tenant.

Each tenant maintains its own lender relationships.

Suggested:

```text
lenders
```

Fields:

```text
id
uuid
tenant_id

lender_code

name
legal_name
registration_number

contact_name
contact_email
contact_phone

status

notes

created_by

created_at
updated_at
deleted_at nullable
```

Status:

```text
DRAFT
ACTIVE
SUSPENDED
INACTIVE
```

Important:

Two tenants can each have a lender named:

```text
ABC Finance
```

They are independent records.

This is intentional.

---

# LENDER USERS

Lender users are tenant users associated with a lender.

Create:

```text
lender_users
```

Fields:

```text
id
tenant_id
lender_id
user_id

lender_role

status

created_at
updated_at
```

Enforce:

```text
lender_users.tenant_id
=
lenders.tenant_id
=
users.tenant_id
```

Prefer database constraints where feasible and service-level validation.

Lender users must never see competing lender data.

---

# LOAN PRODUCTS

Each lender manages one or more products.

Create:

```text
loan_products
```

Fields:

```text
id
uuid
tenant_id
lender_id

code
name
description

product_type

currency

minimum_amount
maximum_amount

minimum_term
maximum_term
term_unit

rate_type
base_rate

minimum_rate nullable
maximum_rate nullable

status

effective_from
effective_to

created_by
approved_by nullable

created_at
updated_at
```

Initial rate types:

```text
FIXED
RANGE
```

Design so future types may include:

```text
TIERED
RISK_BASED
```

but do not implement unnecessary complexity now.

---

# PRODUCT ELIGIBILITY

Do not build AI underwriting.

Use deterministic eligibility rules.

Potential conditions:

```text
minimum_age
maximum_age

minimum_income

employment_type

minimum_employment_months

minimum_amount
maximum_amount

minimum_term
maximum_term

borrower_type

loan_purpose
```

Either model important rules explicitly or introduce:

```text
loan_product_rules
```

Fields may include:

```text
id
tenant_id
loan_product_id

field
operator
value

failure_message

status
effective_from
effective_to
```

Avoid an overly generic rule engine in V1.

---

# BORROWERS

Review LAMaS `Customer`.

Target model:

```text
Borrower
```

Core table:

```text
borrowers
```

Fields:

```text
id
uuid
tenant_id

borrower_number

first_name
middle_name nullable
last_name

date_of_birth

email nullable
phone

borrower_type
status

assigned_agent_id

created_by

created_at
updated_at
deleted_at nullable
```

Supporting tables:

```text
borrower_addresses
borrower_employment
borrower_income
borrower_documents
```

Every supporting table must also be tenant-scoped.

Even if tenant ownership can be derived through `borrower_id`, include `tenant_id` where it improves security, indexing, and direct querying.

---

# BORROWER DOCUMENTS

No OCR in V1.

Create:

```text
borrower_documents
```

Fields:

```text
id
uuid
tenant_id

borrower_id

document_type

storage_disk
storage_path

original_filename
mime_type
file_size

status

uploaded_by
uploaded_at

verified_by nullable
verified_at nullable

rejection_reason nullable
expires_at nullable
```

Status:

```text
UPLOADED
VERIFIED
REJECTED
EXPIRED
```

Documents must use private storage.

All downloads must pass authorization checks.

---

# APPLICATIONS

Study LAMaS `LoanApplication`.

Adapt useful logic.

Target:

```text
applications
```

Fields:

```text
id
uuid
tenant_id

reference

borrower_id
agent_id

requested_amount
requested_term
term_unit

purpose

currency

status

submitted_at nullable

created_by
updated_by

created_at
updated_at
```

Do NOT place a single `lender_id` on the application as its main ownership relationship.

One application can be submitted to several lenders.

---

# APPLICATION STATE MACHINE

Create:

```text
ApplicationWorkflowService
```

Do not allow arbitrary status changes.

Suggested statuses:

```text
DRAFT
READY
SUBMITTED
MATCHING
MATCHED
QUOTED
CUSTOMER_SELECTED
LENDER_REVIEW
APPROVED
CUSTOMER_ACCEPTED
DISBURSED
```

Alternative/terminal:

```text
REJECTED
WITHDRAWN
CANCELLED
EXPIRED
```

Possible later:

```text
CLOSED
DEFAULTED
RESTRUCTURED
```

Create:

```text
application_status_history
```

Fields:

```text
id
tenant_id
application_id

from_status
to_status

reason nullable

changed_by

created_at
```

---

# LENDER MATCHING

Create:

```text
LenderMatchingService
```

Its responsibility:

> Determine which lender products appear eligible for an application.

It is NOT final underwriting.

Inputs:

```text
borrower profile
income/employment
requested amount
requested term
loan purpose
active lenders
active products
eligibility rules
```

Output:

```text
lender_matches
```

Fields:

```text
id
tenant_id

application_id
lender_id
loan_product_id

status

matched_at

rule_snapshot JSONB nullable
failure_reasons JSONB nullable

created_at
```

Statuses:

```text
ELIGIBLE
INELIGIBLE
REVIEW_REQUIRED
```

Important:

```text
ELIGIBLE ≠ APPROVED
```

---

# LENDER SUBMISSIONS

Create:

```text
lender_submissions
```

Fields:

```text
id
uuid
tenant_id

application_id
lender_id
loan_product_id

submission_reference

status

submitted_at
reviewed_at nullable

decision_reason nullable
decision_notes nullable

created_at
updated_at
```

Statuses:

```text
DRAFT
SUBMITTED
UNDER_REVIEW
MORE_INFORMATION_REQUIRED
APPROVED
REJECTED
COUNTERED
WITHDRAWN
EXPIRED
```

A single application may have multiple lender submissions.

---

# LENDER PORTAL

Lender users authenticate within the tenant.

They must only see records matching:

```text
tenant_id = current tenant
AND
lender_id = current lender
```

Lender dashboard:

```text
New Applications
Under Review
Information Requested
Approved
Rejected
Counteroffers
Disbursed
```

Actions:

```text
Request More Information
Approve
Reject
Counteroffer
Add Internal Note
```

Internal lender notes must not automatically become visible to agency users or borrowers.

---

# LENDER QUOTES

Lender quote represents lender-supplied commercial terms.

Create:

```text
lender_quotes
```

Fields:

```text
id
uuid
tenant_id

lender_submission_id

lender_id
loan_product_id

principal

base_rate
lender_fees

term
term_unit

repayment_amount
total_repayable

status
version

valid_from
expires_at

created_by

created_at
updated_at
```

Never overwrite historical quotes.

Counteroffers create another quote/version.

---

# MARKUP LIMITS

Create:

```text
markup_limits
```

Fields:

```text
id
tenant_id

lender_id nullable
loan_product_id nullable

minimum_markup
default_markup
maximum_markup

requires_approval_above nullable

effective_from
effective_to

status

created_by
approved_by nullable

created_at
updated_at
```

Product-level configuration may override lender-level defaults.

Resolve hierarchy explicitly in one service.

Do not scatter precedence rules throughout the codebase.

---

# MARKUP RULES

Create:

```text
markup_rules
```

Fields:

```text
id
tenant_id

lender_id nullable
loan_product_id nullable

minimum_amount nullable
maximum_amount nullable

minimum_term nullable
maximum_term nullable

markup_type
markup_value

effective_from
effective_to

status

created_by
approved_by nullable

created_at
updated_at
```

Initial types:

```text
PERCENTAGE_POINTS
FIXED_FEE
```

No arbitrary executable formulas.

---

# PRICING SERVICE

Create:

```text
PricingService
```

Responsibilities:

1. receive lender quote;
2. resolve applicable markup limit;
3. resolve default markup rule;
4. validate requested markup;
5. determine whether approval is required;
6. calculate customer rate;
7. calculate agency fees;
8. calculate repayment terms if appropriate;
9. create immutable commercial snapshot.

Authoritative formula:

```text
customer_rate =
lender_base_rate
+
agency_markup
```

Never calculate persisted financial outcomes only in Vue.

Laravel is authoritative.

---

# PRICING OVERRIDES

Create:

```text
pricing_overrides
```

Fields:

```text
id
uuid
tenant_id

application_id
lender_quote_id

normal_markup
requested_markup

reason

requested_by

status

approved_by nullable
approved_at nullable

rejection_reason nullable

created_at
updated_at
```

Statuses:

```text
PENDING
APPROVED
REJECTED
CANCELLED
```

Agents must not silently exceed markup authority.

---

# CUSTOMER OFFERS

Do not combine customer offers with lender quotes.

Create:

```text
offers
```

Fields:

```text
id
uuid
tenant_id

application_id
lender_quote_id

lender_id
loan_product_id

borrower_id
agent_id

principal

lender_base_rate
agency_markup
customer_rate

lender_fee
agency_fee
total_fees

term
term_unit

repayment_amount
total_repayable

status
version

valid_from
expires_at

selected_at nullable
selected_by nullable

created_by

created_at
updated_at
```

Statuses:

```text
DRAFT
PRESENTED
VIEWED
SELECTED
DECLINED
EXPIRED
SUPERSEDED
```

Commercial values are snapshots.

Changing the LoanProduct or markup configuration later must never change an existing offer.

---

# OFFER SELECTION

Selecting an offer must:

1. confirm tenant ownership;
2. confirm application ownership;
3. confirm offer is valid;
4. confirm it has not expired;
5. prevent duplicate selection;
6. record `selected_at`;
7. record `selected_by`;
8. transition application state;
9. preserve unselected offers;
10. generate audit event;
11. execute inside a PostgreSQL transaction.

Use locking where necessary.

---

# LOANS

Create a loan after the correct lender/customer acceptance process.

Create:

```text
loans
```

Fields:

```text
id
uuid
tenant_id

reference

application_id
offer_id

borrower_id
agent_id

lender_id
loan_product_id

principal

lender_rate
agency_markup
customer_rate

lender_fees
agency_fees

term
term_unit

repayment_amount
total_repayable

status

approved_at nullable
accepted_at nullable
disbursed_at nullable
maturity_date nullable

created_at
updated_at
```

Loan terms are historical snapshots.

Do not reconstruct them using the current LoanProduct.

---

# LOAN STATUS

Initial:

```text
PENDING
APPROVED
ACTIVE
CLOSED
CANCELLED
DEFAULTED
WRITTEN_OFF
RESTRUCTURED
```

Do not implement complex servicing unless required.

---

# LOAN LEDGER

Create an immutable financial event foundation.

Table:

```text
loan_ledger
```

Fields:

```text
id
uuid
tenant_id

loan_id

transaction_type

amount
currency

effective_date

reference
external_reference nullable

description

metadata JSONB nullable

created_by

reversed_entry_id nullable

created_at
```

Transaction types:

```text
DISBURSEMENT
REPAYMENT
INTEREST
FEE
PENALTY
ADJUSTMENT
REVERSAL
```

Avoid destructive edits.

Correct financial entries using reversal entries wherever feasible.

Do not automate repayments yet.

---

# DISBURSEMENTS

Prefer a dedicated table:

```text
loan_disbursements
```

Fields:

```text
id
uuid
tenant_id

loan_id

amount
currency

lender_reference

disbursed_at

recorded_by

verified_by nullable
verified_at nullable

created_at
```

A verified disbursement should create the appropriate ledger entry.

---

# COMMISSION RULES

Markup and commission are independent.

Create:

```text
commission_rules
```

Fields:

```text
id
tenant_id

lender_id nullable
loan_product_id nullable

commission_type
commission_value

agent_share_type
agent_share_value

effective_from
effective_to

status

created_by
approved_by nullable

created_at
updated_at
```

Potential commission types:

```text
PERCENTAGE_OF_PRINCIPAL
FIXED_AMOUNT
PERCENTAGE_OF_AGENCY_MARGIN
```

Only implement required types.

---

# COMMISSIONS

Create:

```text
commissions
```

Fields:

```text
id
uuid
tenant_id

loan_id
application_id
offer_id

lender_id
agent_id

gross_commission

agency_share
agent_share

currency

status

calculated_at

earned_at nullable
approved_at nullable
paid_at nullable
reversed_at nullable

created_at
updated_at
```

Statuses:

```text
EXPECTED
EARNED
APPROVED
PAYABLE
PAID
REVERSED
```

Use snapshots/calculated values so historical commissions can always be reproduced.

---

# AUDIT

Create tenant-level:

```text
audit_events
```

Fields:

```text
id
uuid
tenant_id

actor_user_id nullable
actor_role nullable

event_type

subject_type
subject_id

old_values JSONB nullable
new_values JSONB nullable

reason nullable

ip_address nullable
user_agent nullable
request_id nullable

created_at
```

Critical event examples:

```text
LENDER_CREATED
LENDER_UPDATED

LOAN_PRODUCT_CREATED
LOAN_PRODUCT_RATE_CHANGED

MARKUP_LIMIT_CHANGED
MARKUP_RULE_CHANGED

PRICING_OVERRIDE_REQUESTED
PRICING_OVERRIDE_APPROVED
PRICING_OVERRIDE_REJECTED

BORROWER_CREATED

APPLICATION_CREATED
APPLICATION_SUBMITTED
APPLICATION_STATUS_CHANGED

LENDER_MATCHED
APPLICATION_SENT_TO_LENDER

LENDER_APPROVED
LENDER_REJECTED
LENDER_COUNTERED

LENDER_QUOTE_CREATED

OFFER_CREATED
OFFER_PRESENTED
OFFER_SELECTED

LOAN_CREATED
LOAN_DISBURSED

LEDGER_ENTRY_CREATED

COMMISSION_CALCULATED
COMMISSION_APPROVED
COMMISSION_PAID
```

Critical audit rows should be append-only.

---

# PLATFORM AUDIT

Separate platform events into:

```text
platform_audit_logs
```

Examples:

```text
TENANT_CREATED
TENANT_UPDATED
TENANT_SUSPENDED
TENANT_REACTIVATED

DOMAIN_CREATED
DOMAIN_UPDATED

PLATFORM_ADMIN_CREATED

TENANT_IMPERSONATION_STARTED
TENANT_IMPERSONATION_ENDED

PLATFORM_SETTING_CHANGED
```

---

# POSTGRESQL DATA TYPES

Financial values:

```text
NUMERIC(15,2)
```

Interest/markup/commission rates:

```text
NUMERIC(9,4)
```

Never use:

```text
FLOAT
DOUBLE PRECISION
```

for financial calculations.

Use:

```text
TIMESTAMPTZ
```

where timezone-aware timestamps matter.

Use:

```text
JSONB
```

only for genuinely flexible structured data such as:

```text
audit snapshots
eligibility result details
ledger metadata
```

Do not use JSONB instead of a proper relational schema.

---

# TENANT-AWARE INDEXING

Because all tenant data shares one database, indexes are critical.

Common indexes should begin with:

```text
tenant_id
```

where appropriate.

Examples:

```text
INDEX (tenant_id, status)

INDEX (tenant_id, lender_id)

INDEX (tenant_id, borrower_id)

INDEX (tenant_id, agent_id)

INDEX (tenant_id, created_at)

INDEX (tenant_id, application_id)

INDEX (tenant_id, loan_id)
```

Use PostgreSQL EXPLAIN ANALYZE when optimizing heavy queries.

Do not create indexes without considering actual query patterns.

---

# FOREIGN KEY SAFETY

A simple FK such as:

```text
applications.borrower_id → borrowers.id
```

does not by itself prove that both rows belong to the same tenant.

Where practical, use composite constraints such as:

```text
UNIQUE (tenant_id, id)
```

and composite foreign keys:

```text
(tenant_id, borrower_id)
→ borrowers(tenant_id, id)
```

Evaluate this systematically for high-risk relationships.

At minimum, enforce tenant consistency in domain services plus automated tests.

Prefer database enforcement where it is maintainable.

---

# SOFT DELETES

Evaluate soft deletes for:

```text
borrowers
lenders
loan_products
```

Do not soft-delete financial transactions such as:

```text
loan_ledger
audit_events
historical offers
commission history
```

Financial/audit records should generally be immutable or reversed, not deleted.

---

# SERVICES

Create business services only where real domain behavior exists.

Potential:

```text
AgentService

BorrowerService

LenderService

LoanProductService

EligibilityService

LenderMatchingService

LoanApplicationService

ApplicationWorkflowService

LenderSubmissionService

LenderDecisionService

PricingService

OfferService

LoanService

LoanLedgerService

CommissionService

AuditService
```

Controllers should be thin.

Avoid god services.

Avoid service classes that merely wrap one Eloquent call.

---

# FRONTEND

Use:

```text
Vue 3
Inertia.js
TypeScript
Tailwind CSS
Vite
```

Use:

```vue
<script setup lang="ts">
```

where appropriate.

If the SaaS starter currently uses React:

1. inspect all React pages;
2. port infrastructure-critical pages to Vue;
3. preserve backend behavior;
4. establish Vue/Inertia bootstrap;
5. port auth;
6. port central admin;
7. port tenant UI;
8. verify feature parity;
9. remove React dependencies only after Vue parity.

Do not maintain React and Vue long-term.

---

# VUE STRUCTURE

Prefer:

```text
resources/js/
├── app.ts
├── bootstrap.ts
│
├── Components/
│   ├── Forms/
│   ├── Tables/
│   ├── Modals/
│   ├── Status/
│   ├── Money/
│   └── Loans/
│
├── Layouts/
│   ├── PlatformLayout.vue
│   ├── TenantLayout.vue
│   ├── AuthenticatedLayout.vue
│   └── GuestLayout.vue
│
├── Pages/
│   ├── Platform/
│   │   ├── Dashboard.vue
│   │   ├── Tenants/
│   │   ├── Domains/
│   │   ├── Users/
│   │   └── Audit/
│   │
│   └── Tenant/
│       ├── Dashboard.vue
│       ├── Agents/
│       ├── Borrowers/
│       ├── Lenders/
│       ├── LenderUsers/
│       ├── LoanProducts/
│       ├── Applications/
│       ├── Matching/
│       ├── LenderSubmissions/
│       ├── LenderQuotes/
│       ├── Offers/
│       ├── Loans/
│       ├── LoanLedger/
│       ├── MarkupLimits/
│       ├── MarkupRules/
│       ├── PricingOverrides/
│       ├── Commissions/
│       ├── Audit/
│       └── Settings/
│
├── Composables/
│   ├── usePermissions.ts
│   ├── useTenant.ts
│   ├── useCurrency.ts
│   └── useApplicationStatus.ts
│
└── Types/
```

---

# APPLICATION WORKSPACE UX

The application detail screen should become the main workflow workspace.

Include:

```text
Application Summary
Borrower
Requested Loan
Documents
Eligibility Matches
Lender Submissions
Lender Responses
Lender Quotes
Customer Offers
Selected Offer
Loan
Activity / Audit
```

Avoid forcing users through excessive separate pages to process one application.

---

# OFFER COMPARISON UI

Show:

```text
Lender
Product
Principal
Term
Customer Rate
Repayment Amount
Fees
Total Repayable
Expiry
Action
```

Authorized internal users may additionally see:

```text
Lender Base Rate
Agency Markup
Margin
```

Lender users must never see competitor information.

---

# ROLE-AWARE UI

Vue should hide unauthorized actions for UX.

But Laravel remains authoritative.

Never rely on:

```text
v-if permission
```

as the actual security control.

---

# DASHBOARDS

## Agency/Admin

Display:

```text
Applications Today
Applications This Month

Draft
Submitted
Under Review
Approved
Rejected
Disbursed

Total Requested
Total Approved
Total Disbursed

Expected Commission
Earned Commission

Top Lenders
Top Products
Top Agents
```

## Agent

Display:

```text
My Borrowers
My Applications

Documents Outstanding
Offers Available
Applications Requiring Action

Approved Loans
Disbursed Loans

Expected Commission
Earned Commission
Paid Commission
```

## Lender

Display only that lender's tenant-scoped records:

```text
New Applications
Under Review
Information Required
Approved
Rejected
Counteroffers
Disbursed
```

---

# SECURITY TESTS

Write tests proving:

```text
Tenant A user cannot read Tenant B borrower.

Tenant A user cannot update Tenant B borrower.

Tenant A user cannot see Tenant B lenders.

Tenant A user cannot retrieve Tenant B application by guessed UUID.

Tenant A cannot query Tenant B offers.

Tenant A cannot query Tenant B loans.

Tenant A cannot query Tenant B ledger.

Tenant A cannot query Tenant B commissions.

Tenant A cannot query Tenant B audit events.

Tenant API/auth cannot escape tenant boundary.

Lender A user cannot see Lender B submissions.

Lender A user cannot see Lender B quote.

Lender user cannot view agency margin unless explicitly permitted.

Agent cannot modify lender base rate.

Agent cannot exceed markup limits.

Suspended tenant cannot access tenant application.

Tenant admin cannot access platform administration.
```

These are mandatory tests.

---

# QUERY LEAK TESTS

Also test relationships.

For example:

```text
$application->borrower
```

must never return a borrower from a different tenant.

Likewise:

```text
$lender->loanProducts
$loan->offer
$offer->lenderQuote
$commission->agent
```

Tenant consistency must be guaranteed.

---

# PRICING TESTS

Test:

```text
Lender rate: 10%
Markup:       3%

Customer:    13%
```

Test limits:

```text
Minimum: 1%
Maximum: 4%

Requested: 5%
```

Expected:

```text
rejected
```

or:

```text
manager approval required
```

depending on configuration.

---

# HISTORICAL PRICING TEST

Example:

Monday:

```text
Loan Product Rate = 10%
```

Offer generated:

```text
10% + 3% = 13%
```

Friday product changed:

```text
Loan Product Rate = 12%
```

Monday's offer must still show:

```text
10%
3%
13%
```

not:

```text
12%
3%
15%
```

Same applies to existing loans.

---

# WORKFLOW TESTS

Invalid:

```text
DRAFT → DISBURSED
```

must fail.

Valid:

```text
DRAFT
→ READY
→ SUBMITTED
→ MATCHED
→ QUOTED
→ CUSTOMER_SELECTED
→ LENDER_REVIEW
→ APPROVED
→ CUSTOMER_ACCEPTED
→ DISBURSED
```

Test:

```text
REJECTED
COUNTERED
WITHDRAWN
CANCELLED
EXPIRED
```

---

# TRANSACTIONS AND CONCURRENCY

Use PostgreSQL transactions for:

```text
application submission

lender submission creation

lender quote creation

customer offer creation

offer selection

pricing override approval

loan creation

disbursement confirmation

ledger entry creation

commission creation
```

Use appropriate locking for high-risk actions.

Examples:

```text
lockForUpdate()
```

Prevent:

```text
double offer acceptance
double loan creation
duplicate disbursement
duplicate commission creation
```

Use database uniqueness/idempotency where appropriate.

---

# AUDITING FINANCIAL CHANGES

Any change to:

```text
base rate
markup limits
markup rules
offer terms
loan terms
commission rules
ledger entries
disbursement
```

must be auditable.

Do not allow invisible destructive financial edits.

---

# LAMAS DOMAIN MAPPING

Start with this hypothesis:

```text
LAMaS Customer
→ Borrower

LAMaS LoanApplication
→ Application

LAMaS Promoter
→ Agent

LAMaS Broker
→ likely Tenant / Agency concept

LAMaS Company
→ likely replaced by Tenant

LAMaS CreditRisk
→ selectively reuse for eligibility/risk later

LAMaS Portfolio
→ inspect for Loan/portfolio reporting concepts
```

Confirm everything from source before implementing.

---

# IMPLEMENTATION PHASES

## PHASE 0 — AUDIT

Do not change business behavior.

Audit both repositories.

Create architecture documentation.

Produce transplant map.

Produce exact file-change plan.

---

## PHASE 1 — SINGLE-DATABASE TENANCY

Stabilize:

```text
Tenant creation
Tenant resolution
Domain routing
Tenant context
Tenant user authentication
Tenant global scoping
Tenant authorization
Tenant-aware queues
Tenant-aware cache
Tenant-aware storage
Tenant suspension
```

Add cross-tenant security tests.

Do not proceed until isolation is proven.

---

## PHASE 2 — VUE MIGRATION

If starter uses React:

```text
Install/configure Vue 3
Configure Inertia Vue adapter
Create bootstrap
Port authentication
Port platform admin
Port tenant shell
Port tenant management
Test feature parity
Remove React after completion
```

---

## PHASE 3 — TENANT TYPES + RBAC

Implement:

```text
AGENCY
INDEPENDENT_AGENT
```

Add roles and granular permissions.

---

## PHASE 4 — AGENTS

Transplant useful LAMaS Promoter concepts.

Implement Agent model/UI/authorization.

---

## PHASE 5 — BORROWERS

Transplant LAMaS Customer domain.

Implement:

```text
borrowers
addresses
employment
income
documents
```

---

## PHASE 6 — LENDERS

Implement tenant-owned lenders.

Implement lender users.

Implement lender-scoped authorization.

---

## PHASE 7 — LOAN PRODUCTS

Implement:

```text
lender products
base rates
eligibility rules
status/effective dates
```

---

## PHASE 8 — APPLICATIONS

Transplant/adapt LoanApplication.

Implement:

```text
application state machine
status history
validation
tenant/agent ownership
```

---

## PHASE 9 — MATCHING

Implement deterministic:

```text
EligibilityService
LenderMatchingService
```

Generate lender matches.

---

## PHASE 10 — LENDER SUBMISSIONS

Implement:

```text
submit to lender
lender review
request information
approve
reject
counter
```

---

## PHASE 11 — LENDER QUOTES

Implement immutable/versioned lender quotes.

Support counteroffers.

---

## PHASE 12 — MARKUP & PRICING

Implement:

```text
markup limits
markup rules
pricing overrides
PricingService
```

Write extensive tests.

---

## PHASE 13 — OFFERS

Generate customer offers.

Build comparison.

Build presentation/selection workflow.

---

## PHASE 14 — LOANS

Create loans from accepted/approved offers.

Snapshot all commercial values.

---

## PHASE 15 — LEDGER & DISBURSEMENTS

Create:

```text
loan_ledger
loan_disbursements
```

Record disbursement.

Do not automate collections.

---

## PHASE 16 — COMMISSIONS

Implement:

```text
commission rules
agency share
agent share
commission workflow
```

---

## PHASE 17 — AUDIT

Complete:

```text
tenant audit
platform audit
financial audit coverage
```

---

## PHASE 18 — DASHBOARDS

Build:

```text
agency dashboard
agent dashboard
lender dashboard
platform dashboard
```

---

## PHASE 19 — SECURITY HARDENING

Review:

```text
tenant escape
authorization
IDOR
document access
financial mutation
mass assignment
validation
rate limiting
queue tenancy
cache tenancy
storage tenancy
session tenancy
```

---

## PHASE 20 — POSTGRESQL OPTIMIZATION

Review:

```text
indexes
composite indexes
foreign keys
tenant-aware uniqueness
query plans
JSONB usage
N+1 queries
large table pagination
```

Use:

```text
EXPLAIN ANALYZE
```

where useful.

---

## PHASE 21 — RELEASE READINESS

Verify:

```text
fresh installation

migrations

seeders

platform admin creation

tenant creation

agency tenant flow

independent-agent tenant flow

multiple lenders

multiple products

multi-lender application

pricing

quote comparison

loan creation

ledger

commission

tenant isolation

backup/restore

queues

storage

production build
```

---

# DEMO DATA

Create development seeders.

Example:

```text
Tenant:
Prime Loan Agency

Agency Admin:
admin user

Agents:
Jane
Michael

Lenders:
ABC Finance
XYZ Credit
Capital Lending

Products:

ABC Personal Loan
ABC Salary Loan

XYZ Quick Loan
XYZ Business Loan

Capital Vehicle Loan
```

Create several borrowers and test applications.

Also create Tenant B with unrelated records to prove isolation.

---

# MVP ACCEPTANCE TEST

The MVP is complete when this works end-to-end:

```text
1. Platform Admin creates Tenant A.

2. Agency admin logs in.

3. Agency creates Agent A.

4. Agency creates three lenders.

5. Agency creates lender users.

6. Lenders have products.

7. Products have rates and eligibility.

8. Agency defines markup limits/rules.

9. Agent creates borrower.

10. Agent uploads borrower documents.

11. Agent creates loan application.

12. Matching identifies eligible products.

13. Application is submitted to several lenders.

14. Lender users see only their own submissions.

15. Lender A approves.

16. Lender B rejects.

17. Lender C counteroffers.

18. Lender quotes are stored.

19. Pricing engine applies markup.

20. Customer offers are generated.

21. Agent compares offers.

22. Customer selects one.

23. Application transitions correctly.

24. Loan is created.

25. Disbursement is recorded.

26. Loan ledger receives disbursement event.

27. Commission is calculated.

28. Agency share is stored.

29. Agent share is stored.

30. Audit history shows every critical action.

31. Tenant B cannot access any Tenant A data.
```

---

# NON-NEGOTIABLE DEVELOPMENT RULES

Do not write features before inspecting existing code.

Do not duplicate starter functionality.

Do not copy LAMaS blindly.

Do not mix React and Vue long-term.

Do not put business rules in Vue.

Do not put pricing calculations in controllers.

Do not use floats for money.

Do not omit `tenant_id` from tenant-owned records.

Do not trust global scopes alone.

Do not trust frontend permissions.

Do not overwrite historical offers.

Do not overwrite lender quote history.

Do not overwrite historical loan pricing.

Do not collapse lender rate, markup, and customer rate.

Do not delete financial history.

Do not allow cross-tenant relations.

Do not perform critical multi-record financial changes without transactions.

Do not proceed past a phase while its tests are failing.

---

# CHANGE MANAGEMENT PROCESS

For every phase:

1. inspect relevant existing code;
2. explain the current implementation;
3. identify reusable code;
4. identify LAMaS reference code;
5. define the intended change;
6. list files that will change;
7. implement the smallest correct change;
8. run migrations;
9. run backend tests;
10. run frontend build/tests;
11. run tenant-isolation tests;
12. fix failures;
13. document the completed phase;
14. commit logically grouped work if Git workflow permits;
15. move to the next phase only after the current phase is stable.

---

# FINAL ARCHITECTURAL RULE

The application uses:

```text
ONE POSTGRESQL DATABASE
```

Platform-level records:

```text
tenants
domains
central_users
platform_settings
platform_audit_logs
```

Tenant-owned records:

```text
users
agents

lenders
lender_users
loan_products
loan_product_rules

borrowers
borrower_addresses
borrower_employment
borrower_income
borrower_documents

applications
application_status_history

lender_matches
lender_submissions
lender_quotes

markup_limits
markup_rules
pricing_overrides

offers

loans
loan_disbursements
loan_ledger

commission_rules
commissions

audit_events
```

Every tenant-owned record must remain securely isolated through:

```text
tenant_id
tenant context
Eloquent scoping
Laravel Policies
database constraints
tenant-aware unique indexes
automated isolation tests
```

The SaaS starter owns platform architecture.

LAMaS supplies loan-domain knowledge.

Laravel owns security, validation, tenancy, workflows, pricing, financial calculations, and persistence.

Vue owns presentation and interaction.

PostgreSQL is the authoritative datastore.

Build the brokerage system correctly first.

Do not add AI or automation until the domain model, pricing, lender workflow, loans, ledger, commissions, and tenant isolation are stable and tested.