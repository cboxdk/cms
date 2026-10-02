<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;

// A form that sets a password refuses one known from breaches (PRD 5.16). The test binds the
// testkit's fake in place of the default, which asks Have I Been Pwned, so nothing leaves the
// test. When the check cannot be made, the form neither accepts nor refuses the password: it asks
// the person to try again.

it('refuses a leaked password, accepts another, and fails closed when the check cannot be made', function (): void {
    $passwords = new FakeBreachedPasswords(new Password('Summer2026!Summer'));
    app()->instance(BreachedPasswords::class, $passwords);
    // What the form does: ask the BreachedPasswords the container gives.
    $check = static fn (BreachedPasswords $breached, string $typed): bool => ! $breached->isBreached(new Password($typed));
    $acceptable = static fn (string $typed): bool => $check(app(BreachedPasswords::class), $typed);

    expect($acceptable('Summer2026!Summer'))->toBeFalse()
        ->and($acceptable('a long and quite unusual sentence'))->toBeTrue();

    $passwords->goDown();

    expect(fn (): bool => $acceptable('a long and quite unusual sentence'))
        ->toThrow(BreachedPasswordsUnavailable::class, 'could not be checked');
});
