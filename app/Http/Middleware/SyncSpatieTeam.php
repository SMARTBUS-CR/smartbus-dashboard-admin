<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncSpatieTeam
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $teamId = Filament::getTenant()?->getKey();

        if (getPermissionsTeamId() !== $teamId) {
            Filament::auth()->user()?->unsetRelation('roles')->unsetRelation('permissions');
        }

        setPermissionsTeamId($teamId);

        return $next($request);
    }
}
