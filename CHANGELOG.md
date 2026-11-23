# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- Tenancy now uses one PostgreSQL database. Rows are scoped by `tenant_id`. Creating a tenant no longer creates a database.
- `BelongsToTenant` hides every tenant-owned row when no tenant is resolved. Cross-tenant reads go through `acrossTenants()`.
- Login, registration, password reset, and API tokens are scoped to the current tenant. The same email may exist in two tenants.
- A suspended tenant receives 403 on its domain. Tenants are deactivated rather than deleted.
- PHPUnit runs against PostgreSQL and Redis inside Docker (`docker compose run --rm app php artisan test`).

### Fixed

- Tenant isolation tests called `tenancy()->run()`, which stancl/tenancy 3.9 does not provide. They now use `$tenant->run()`.
