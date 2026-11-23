<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenant()?->isActive()) {
            abort(403, 'This account is suspended.');
        }

        return $next($request);
    }
}
