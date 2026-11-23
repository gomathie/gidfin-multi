<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public const TENANT_SCOPE = 'tenant';

    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if ($model->tenant_id === null && tenant()) {
                $model->tenant_id = tenant()->getTenantKey();
            }
        });

        // Fails closed: with no tenant resolved (central routes, console, unbound jobs) only
        // rows without a tenant match, so business tables return nothing and `users` returns
        // platform admins only. Cross-tenant reads must remove this scope explicitly.
        static::addGlobalScope(self::TENANT_SCOPE, function (Builder $query) {
            $column = $query->getModel()->qualifyColumn('tenant_id');

            tenant()
                ? $query->where($column, tenant()->getTenantKey())
                : $query->whereNull($column);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::TENANT_SCOPE);
    }
}
