<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

use function in_array;

class SetLocale
{
    /**
     * Handle an incoming request and set application locale based on Accept-Language header.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language');
        $supportedLocales = ['es', 'en'];

        if ($locale && in_array($locale, $supportedLocales, true)) {
            App::setLocale($locale);
        } else {
            App::setLocale(config('app.fallback_locale', 'en'));
        }

        return $next($request);
    }
}
