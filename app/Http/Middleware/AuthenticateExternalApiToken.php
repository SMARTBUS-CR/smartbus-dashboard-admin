<?php

namespace App\Http\Middleware;

use App\Enums\TokenValidationStatus;
use App\Models\User;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateExternalApiToken
{
    public function __construct(
        protected AuthService $authService,
    ) {}

    /**
     * Handle an incoming API request and authenticate via external Sanctum token.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        // 1. If an actor is already set on the request (e.g., $this->actingAs in tests)
        $user = $request->user();

        if (! $user) {
            $token = $request->bearerToken();

            if (! $token) {
                return response()->json([
                    'errors' => [
                        [
                            'status' => '401',
                            'title' => 'Unauthorized',
                            'detail' => __('Authentication token required.'),
                        ],
                    ],
                ], Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/vnd.api+json']);
            }

            // 2. Validate token against the Authentication microservice via AuthService
            $status = $this->authService->validateToken($token);

            if ($status !== TokenValidationStatus::Valid) {
                $httpStatus = match ($status) {
                    TokenValidationStatus::Forbidden => Response::HTTP_FORBIDDEN,
                    TokenValidationStatus::Unavailable => Response::HTTP_SERVICE_UNAVAILABLE,
                    default => Response::HTTP_UNAUTHORIZED,
                };

                return response()->json([
                    'errors' => [
                        [
                            'status' => (string) $httpStatus,
                            'title' => Response::$statusTexts[$httpStatus],
                            'detail' => $status === TokenValidationStatus::Unavailable
                                ? __('Authentication could not be confirmed. Please try again shortly.')
                                : __('The access token is invalid or expired.'),
                        ],
                    ],
                ], $httpStatus, ['Content-Type' => 'application/vnd.api+json']);
            }

            // 3. Resolve user details from Authentication microservice
            $userData = $this->authService->getUser($token);

            if (! $userData || empty($userData['id'])) {
                return response()->json([
                    'errors' => [
                        [
                            'status' => '503',
                            'title' => 'Service Unavailable',
                            'detail' => __('Authentication could not be confirmed. Please try again shortly.'),
                        ],
                    ],
                ], Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'application/vnd.api+json']);
            }

            $user = User::query()->find($userData['id']);

            if (! $user) {
                return response()->json([
                    'errors' => [
                        [
                            'status' => '401',
                            'title' => 'Unauthorized',
                            'detail' => __('User not found in dashboard.'),
                        ],
                    ],
                ], Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/vnd.api+json']);
            }

            // 4. Set the user exclusively on the request resolver (stateless in-memory, no web session interference)
            $request->setUserResolver(fn () => $user);
        }

        // 5. Check permission if specified on the middleware
        if ($permission && ! $user->isSuperAdmin() && ! $user->can($permission)) {
            return response()->json([
                'errors' => [
                    [
                        'status' => '403',
                        'title' => 'Forbidden',
                        'detail' => __('You do not have permission to access this resource.'),
                    ],
                ],
            ], Response::HTTP_FORBIDDEN, ['Content-Type' => 'application/vnd.api+json']);
        }

        return $next($request);
    }
}
