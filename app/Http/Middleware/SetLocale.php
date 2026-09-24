<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the request locale.
 *
 * Arabic is the default; a user may carry their own preference. Carbon is set
 * alongside so translated dates follow the interface language, which is what
 * makes English localisation a configuration change rather than a rewrite.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Token-authenticated API requests have no session, so it is checked
        // for rather than assumed.
        $locale = $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? config('app.locale');

        if (! in_array($locale, config('app.supported_locales', ['ar', 'en']), true)) {
            $locale = config('app.locale');
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
