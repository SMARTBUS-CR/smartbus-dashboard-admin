<?php

namespace App\Enums;

/**
 * This enum represents the possible outcomes of validating an authentication token.
 *
 * Valid: The token is valid and can be used for authentication.
 * Invalid: The token is invalid or has expired.
 * Forbidden: The token isn't valid nor invalid, the user does not have the necessary permissions.
 * Unavailable: The token validation service is unavailable or the response was inconclusive.
 */
enum TokenValidationStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Forbidden = 'forbidden';
    case Unavailable = 'unavailable';
}
