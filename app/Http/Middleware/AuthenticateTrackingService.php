<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTrackingService
{
    public const ETA_CONTEXT_ATTRIBUTE = 'eta_tracking_context';

    private const CLOCK_TOLERANCE_SECONDS = 300;

    /**
     * Authenticate GPS independently of user sessions and external Auth.
     *
     * X-Service-Timestamp is Unix seconds; X-Service-Signature is lowercase
     * hexadecimal HMAC-SHA256 over four LF-separated values, with no final LF:
     * timestamp, uppercase HTTP method, raw path/query, SHA-256 of the raw body.
     * The dedicated GPS secret is shared through environment configuration.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $projection = null): Response
    {
        if ($projection === 'eta-v1' && $request->query('projection') === $projection) {
            return $this->authenticateEta($request, $next);
        }

        $secret = config('services.smartbus.tracking_context_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return $this->errorResponse(Response::HTTP_SERVICE_UNAVAILABLE, __('Tracking service authentication is not configured.'));
        }

        $timestamp = $request->header('X-Service-Timestamp', '');
        $signature = $request->header('X-Service-Signature', '');
        $message = implode("\n", [
            $timestamp,
            $request->getMethod(),
            $request->getRequestUri(),
            hash('sha256', $request->getContent()),
        ]);

        if (! preg_match('/\A[0-9]{1,12}\z/', $timestamp)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
            || abs(now()->timestamp - (int) $timestamp) > self::CLOCK_TOLERANCE_SECONDS
            || ! hash_equals(hash_hmac('sha256', $message, $secret), $signature)) {
            return $this->errorResponse(Response::HTTP_UNAUTHORIZED, __('A valid GPS service signature is required.'));
        }

        return $next($request);
    }

    /** @param Closure(Request): (Response) $next */
    private function authenticateEta(Request $request, Closure $next): Response
    {
        $token = config('services.smartbus.trip_context_token');
        if (! is_string($token) || trim($token) === '') {
            return $this->errorResponse(Response::HTTP_SERVICE_UNAVAILABLE, __('Tracking service authentication is not configured.'));
        }

        if (! preg_match('/\ABearer ([A-Za-z0-9._~+\/-]+=*)\z/i', $request->header('Authorization', ''), $matches)
            || ! hash_equals($token, $matches[1])) {
            return $this->errorResponse(Response::HTTP_UNAUTHORIZED, __('A valid ETA service token is required.'))
                ->header('WWW-Authenticate', 'Bearer');
        }

        $request->attributes->set(self::ETA_CONTEXT_ATTRIBUTE, true);

        return $next($request);
    }

    private function errorResponse(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => (string) $status,
                'title' => Response::$statusTexts[$status],
                'detail' => $detail,
            ]],
        ], $status, ['Content-Type' => 'application/vnd.api+json']);
    }
}
