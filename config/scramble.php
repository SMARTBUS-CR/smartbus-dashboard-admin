<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Which routes to document.
     */
    'api_path' => 'api',

    /*
     * Your API domain. By default, app domain is used.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'API for SmartBus **Operational Dashboard & Transport Services**. This service is responsible for managing transport catalogues (routes, patterns, stops, fares), operational planning, and trip execution. <br><br> All endpoints described follow the [JSON:API](https://jsonapi.org/) specification for request and response formats.',
    ],

    'ui' => [
        'title' => 'SmartBus Operational Dashboard API',
    ],

    'renderer' => 'scalar',

    'renderers' => [
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'purple',
            'proxyUrl' => null,
            'darkMode' => true,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    'servers' => null,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],
];
