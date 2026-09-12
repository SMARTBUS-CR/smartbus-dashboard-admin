<?php

declare(strict_types=1);

return [
    'email' => [
        'label' => 'Email address',
    ],
    'password' => [
        'label' => 'Password',
    ],
    'password-confirmation' => [
        'label' => 'Confirm password',
    ],
    'request-password' => [
        'code' => [
            'label' => 'Verification code',
        ],
        'actions' => [
            'request' => [
                'label' => 'Send code',
            ],
            'confirm-reset' => [
                'label' => 'Change password',
            ],
        ],
    ],
    'register-tenant' => [
        'title' => 'Register new company',
        'description' => 'Create a new company account',
        'actions' => [
            'register' => [
                'label' => 'Register',
            ],
            'back' => [
                'label' => 'Go back',
            ],
        ],
    ],
];
