<?php

use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Password Reset Page', function (): void {
    test('allows guests to access password recovery from the login page', function (): void {
        app()->setLocale('en');

        $this->get(route('filament.admin.auth.login'))
            ->assertSuccessful()
            ->assertSee(
                __('filament-panels::auth/pages/login.actions.request_password_reset.label'),
            );

        $recoveryUrl = Filament::getPanel('admin')
            ->getRequestPasswordResetUrl();

        expect($recoveryUrl)->toBeString();

        $this->get($recoveryUrl)
            ->assertSuccessful();

        $this->assertGuest('web');
    });

    test('requests a recovery code through the gateway without signing in the user', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', true);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/forgot'
                && $request->data() === [
                    'email' => 'admin@example.com',
                ],
        );

        Http::assertSentCount(1);

        $this->assertGuest('web');
    });

    test('resets the password using the requested code and clears sensitive fields', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
            'https://gateway.test/auth/password/reset' => Http::response([
                'meta' => [
                    'message' => 'Your password has been reset.',
                ],
            ]),
        ]);

        $page = Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', true);

        $page
            ->fillForm([
                'code' => '123456',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])
            ->call('request')
            ->assertHasNoFormErrors();

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/reset'
                && $request->data() === [
                    'email' => 'admin@example.com',
                    'code' => '123456',
                    'password' => 'NewSecurePassword123!',
                    'password_confirmation' => 'NewSecurePassword123!',
                ],
        );

        Http::assertSentCount(2);

        $page
            ->assertSet('showResetForm', false)
            ->assertSet('data.code', null)
            ->assertSet('data.password', null)
            ->assertSet('data.password_confirmation', null)
            ->assertRedirect(
                Filament::getPanel('admin')->getLoginUrl(),
            );

        Livewire::test(Login::class)
            ->assertSet('data.email', 'admin@example.com')
            ->assertSet('data.password', null);

        $this->assertGuest('web');
    });

    test('rejects mismatched passwords without sending a reset request', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->fillForm([
                'code' => '123456',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'DifferentPassword123!',
            ])
            ->call('request')
            ->assertHasFormErrors([
                'password_confirmation' => 'same',
            ])
            ->assertSet('showResetForm', true)
            ->assertSet('requestedEmail', 'admin@example.com');

        Http::assertNotSent(
            fn (Request $request): bool => $request->url() === 'https://gateway.test/auth/password/reset',
        );

        Http::assertSentCount(1);

        $this->assertGuest('web');
    });

    test('reports an expired code and keeps the reset form available', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
            'https://gateway.test/auth/password/reset' => Http::response([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Code Expired',
                        'detail' => 'The reset code has expired.',
                    ],
                ],
            ], 400),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->fillForm([
                'code' => '123456',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', true)
            ->assertSet('requestedEmail', 'admin@example.com');

        Notification::assertNotified(
            Notification::make()
                ->title(__('Password Recovery Failed'))
                ->body(__('The reset code has expired.'))
                ->danger(),
        );

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/reset',
        );

        Http::assertSentCount(2);

        $this->assertGuest('web');
    });

    test('uses the requested email even when the disabled email field is modified', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
            'https://gateway.test/auth/password/reset' => Http::response([
                'meta' => [
                    'message' => 'Your password has been reset.',
                ],
            ]),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('requestedEmail', 'admin@example.com')
            ->set('data.email', 'another@example.com')
            ->fillForm([
                'code' => '123456',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', false);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/auth/password/reset'
                && $request['email'] === 'admin@example.com',
        );

        Http::assertSentCount(2);

        $this->assertGuest('web');
    });

    test('allows correcting an incorrect code without the initial request consuming a confirmation attempt', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
            'https://gateway.test/auth/password/reset' => Http::sequence()
                ->push([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Incorrect Code',
                            'detail' => 'The reset code is incorrect.',
                        ],
                    ],
                ], 400)
                ->push([
                    'meta' => [
                        'message' => 'Your password has been reset.',
                    ],
                ]),
        ]);

        $page = Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->fillForm([
                'code' => '111111',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', true);

        $page
            ->fillForm([
                'code' => '123456',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', false);

        Http::assertSentCount(3);

        $this->assertGuest('web');
    });

    test('blocks the third password reset confirmation attempt', function (): void {
        Filament::setCurrentPanel('admin');

        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'A password reset code has been sent to your email.',
                ],
            ]),
            'https://gateway.test/auth/password/reset' => Http::response([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Incorrect Code',
                        'detail' => 'The reset code is incorrect.',
                    ],
                ],
            ], 400),
        ]);

        $page = Livewire::test(RequestPasswordReset::class)
            ->fillForm([
                'email' => 'admin@example.com',
            ])
            ->call('request')
            ->assertHasNoFormErrors()
            ->fillForm([
                'code' => '111111',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ]);

        foreach (range(1, 2) as $attempt) {
            $page
                ->call('request')
                ->assertHasNoFormErrors()
                ->assertSet('showResetForm', true);
        }

        Http::assertSentCount(3);

        $page
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('showResetForm', true);

        Http::assertSentCount(3);

        $this->assertGuest('web');
    });
});
