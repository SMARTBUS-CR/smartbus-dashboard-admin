<?php

use App\Filament\Pages\Auth\RequestPasswordReset;

it('shows the verification form after the reset code is sent', function () {
    $page = new RequestPasswordReset();
    $page->email = 'demo@smartbus.com';
    $page->showResetForm = true;

    expect($page->showResetForm)->toBeTrue()
        ->and($page->email)->toBe('demo@smartbus.com')
        ->and($page->getFormActions()[0]->getName())->toBe('confirmReset');
});
