<?php

declare(strict_types=1);

return [
    'email' => [
        'label' => 'Email Address',
    ],
    'password' => [
        'label' => 'Password',
    ],
    'password-confirmation' => [
        'label' => 'Confirm Password',
    ],
    'request-password' => [
        'code' => [
            'label' => 'Verification Code',
        ],
        'actions' => [
            'request' => [
                'label' => 'Send Code',
            ],
            'confirm-reset' => [
                'label' => 'Change Password',
            ],
        ],
    ],
];
