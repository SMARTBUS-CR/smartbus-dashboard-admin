<?php

namespace App\Services;

class UserService
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.smartbus.gateway.url'), '/');
        $this->timeout = (int) config('services.smartbus.gateway.timeout', 30);
    }
}
