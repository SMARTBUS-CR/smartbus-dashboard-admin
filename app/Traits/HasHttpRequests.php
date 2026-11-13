<?php

namespace App\Traits;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

trait HasHttpRequests
{
    /**
     * Build a pre-configured PendingRequest with common headers, base URL, and timeout.
     *
     * @param  string|null  $token  The authentication token to include in the request headers, if any.
     * @return PendingRequest The configured HTTP client instance ready to send requests.
     */
    protected function buildHttpClient(?string $token = null): PendingRequest
    {
        $request = Http::timeout($this->getTimeout())
            ->accept('application/vnd.api+json, application/json');

        if ($this->getBaseUrl()) {
            $request->baseUrl($this->getBaseUrl());
        }

        if ($token) {
            $request->withToken($token);
        }

        return $request;
    }

    /**
     * Helper to perform HTTP requests directly.
     *
     * @param  string  $method  The HTTP method to use (GET, POST, PUT, DELETE, etc.).
     * @param  string  $endpoint  The endpoint path to send the request to, relative to the base URL.
     * @param  array<string, mixed>  $data  The data to send with the request, typically as JSON for non-GET methods.
     * @param  array<string, mixed>  $queryParams  The query parameters to append to the request URL.
     * @param  string|null  $token  The authentication token to include in the request headers, if any.
     * @return Response The response from the HTTP request.
     */
    protected function sendHttpRequest(
        string $method,
        string $endpoint,
        array $data = [],
        array $queryParams = [],
        ?string $token = null
    ): Response {
        $client = $this->buildHttpClient($token);

        if (! empty($queryParams)) {
            $client->withQueryParameters($queryParams);
        }

        return $client->send(strtoupper($method), ltrim($endpoint, '/'), array_filter([
            'json' => $method !== 'GET' ? $data : null,
        ]));
    }

    /**
     * Abstract or default getter for Base URL.
     *
     * @return string The base URL for the HTTP client.
     */
    protected function getBaseUrl(): string
    {
        return property_exists($this, 'baseUrl') ? $this->baseUrl : '';
    }

    /**
     * Abstract or default getter for Timeout.
     *
     * @return int The timeout duration in seconds for the HTTP client.
     */
    protected function getTimeout(): int
    {
        return property_exists($this, 'timeout') ? $this->timeout : 30;
    }
}
