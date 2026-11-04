<?php

namespace App\Exceptions;

use App\Services\DriverRegistrationOperation;
use RuntimeException;
use Throwable;

class DriverRegistrationCompensationException extends RuntimeException
{
    /** @param list<Throwable> $recoveryFailures */
    public function __construct(
        public readonly DriverRegistrationOperation $operation,
        public readonly array $recoveryFailures,
        Throwable $registrationFailure,
    ) {
        parent::__construct('Driver registration failed and recovery requires intervention.', 0, $registrationFailure);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            ...$this->operation->context(),
            'recovery_failures' => array_map(static fn (Throwable $failure): string => $failure::class, $this->recoveryFailures),
        ];
    }
}
