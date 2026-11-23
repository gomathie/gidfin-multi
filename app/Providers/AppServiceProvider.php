<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // The token is keyed by tenant and email, but the link carries the plain email
        // because the reset form resolves the user inside the tenant's own domain.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => route('tenant.password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]));
    }
}
