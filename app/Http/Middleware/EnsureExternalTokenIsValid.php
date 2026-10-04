<?php

namespace App\Http\Middleware;

use App\Enums\TokenValidationStatus;
use App\Services\AuthService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

class EnsureExternalTokenIsValid
{
    public function __construct(
        protected AuthService $authService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->session()->get('external_auth_token');

        $status = is_string($token) && trim($token) !== ''
            ? $this->authService->validateToken($token)
            : TokenValidationStatus::Invalid;

        if ($status === TokenValidationStatus::Invalid) {
            Filament::auth()->logout();

            return redirect()->to(Filament::getLoginUrl());
        }

        if ($status === TokenValidationStatus::Forbidden) {
            abort(
                Response::HTTP_FORBIDDEN,
                __('Access was denied by the authentication service.'),
            );
        }

        if ($status === TokenValidationStatus::Unavailable) {
            abort(
                Response::HTTP_SERVICE_UNAVAILABLE,
                __('Authentication could not be confirmed. Please try again shortly.'),
            );
        }

        return $next($request);
    }
}
