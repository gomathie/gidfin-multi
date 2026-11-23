<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantService
{
    public function createTenant(array $data): Tenant
    {
        $subdomain = $data['domain'];
        $fullDomain = $subdomain.'.'.config('app.domain');

        return DB::transaction(function () use ($data, $subdomain, $fullDomain) {
            $tenant = Tenant::create([
                'id' => $subdomain,
                'name' => $data['name'],
                'admin_email' => $data['admin_email'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $tenant->domains()->create([
                'domain' => $fullDomain,
            ]);

            // Without a supplied password the admin gets an unguessable one and signs in
            // through "forgot password" or platform impersonation.
            (new User)->forceFill([
                'tenant_id' => $tenant->id,
                'name' => 'Admin',
                'email' => $data['admin_email'],
                'email_verified_at' => now(),
                'password' => $data['admin_password'] ?? Str::password(40),
                'role' => 'admin',
            ])->save();

            return $tenant->load('domains');
        });
    }

    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        $tenant->update([
            'name' => $data['name'],
            'admin_email' => $data['admin_email'] ?? $tenant->admin_email,
            'is_active' => $data['is_active'] ?? $tenant->is_active,
        ]);

        return $tenant->fresh()->load('domains');
    }

    /**
     * Tenants own financial records, so they are deactivated rather than deleted.
     *
     * @throws InvalidArgumentException
     */
    public function deleteTenant(Tenant $tenant): never
    {
        throw new InvalidArgumentException('Tenants cannot be deleted. Deactivate the tenant instead.');
    }

    public function toggleStatus(Tenant $tenant): Tenant
    {
        $tenant->update([
            'is_active' => ! $tenant->is_active,
        ]);

        return $tenant->fresh();
    }

    public function generateTenantId(string $name): string
    {
        $baseId = Str::slug($name);
        $id = $baseId;
        $counter = 1;

        while (Tenant::where('id', $id)->exists()) {
            $id = $baseId.'-'.$counter;
            $counter++;
        }

        return $id;
    }
}
