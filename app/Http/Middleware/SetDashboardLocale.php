<?php

namespace App\Http\Middleware;

use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;

class SetDashboardLocale
{
    public function __construct(private LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next)
    {
        $this->locales->apply($request);

        return $next($request);
    }
}
