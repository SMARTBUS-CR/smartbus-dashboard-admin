<?php

declare(strict_types=1);

return [
    'email' => [
        'label' => 'Correo electrónico',
    ],
    'password' => [
        'label' => 'Contraseña',
    ],
    'password-confirmation' => [
        'label' => 'Confirmar contraseña',
    ],
    'request-password' => [
        'code' => [
            'label' => 'Código de verificación',
        ],
        'actions' => [
            'request' => [
                'label' => 'Enviar código',
            ],
            'confirm-reset' => [
                'label' => 'Cambiar contraseña',
            ],
        ],
    ],
];
