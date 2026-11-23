<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable;

    public const ROLES = [
        'admin' => 'Admin',
        'user' => 'User',
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'is_admin',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->is_admin === true && $this->tenant_id === null;
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public static function uniqueEmailRule(?int $ignoreId = null): Unique
    {
        $rule = Rule::unique('users', 'email')->where('tenant_id', tenant()?->getTenantKey());

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    // Emails repeat across tenants, so reset tokens are keyed by tenant and email together.
    public function getEmailForPasswordReset(): string
    {
        return ($this->tenant_id ?? '').'|'.$this->email;
    }
}
