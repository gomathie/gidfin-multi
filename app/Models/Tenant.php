<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    use HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'admin_email',
            'is_active',
        ];
    }

    protected $fillable = [
        'id',
        'name',
        'admin_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function primaryDomain(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->domains->first()?->domain,
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->acrossTenants();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }
}
