---
title: Breached passwords
weight: 44
description: "The BreachedPasswords contract: whether a password is known from data breaches, the sensitive Password value, the default on Have I Been Pwned's range API with k-anonymity through the egress gateway, failing closed, the testkit's FakeBreachedPasswords and the shared suite BreachedPasswordsContract with its harness."
---

# Breached passwords

<!-- extension-point: Cbox\Cms\Contracts\Identity\BreachedPasswords -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\BreachedPasswordsHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Identity\BreachedPasswordsContract -->

A local account refuses a password that is known from data breaches (PRD 5.16). `Cbox\Cms\Contracts\Identity\BreachedPasswords` answers the question. It is `#[Experimental]`.

## The contract

`isBreached(Password $password): bool` is true when the password is known from a breach and false when it is not. When it cannot tell, because the service it asks does not answer or answers something it cannot read, it throws `BreachedPasswordsUnavailable` with the code [`breached_passwords_unavailable`](../../reference/errors.md#breached_passwords_unavailable), which is retryable. It fails closed: it never answers false for a password it could not check, so the caller neither accepts nor refuses the password and asks the person to try again.

An implementation never stores, logs or sends the password. One that asks a service sends at most what cannot identify the password.

## The password

`Cbox\Cms\Contracts\Identity\Password` holds a password someone typed, for as long as the call that checks or hashes it. `reveal()` is the only way to read it. Its string form, `json_encode()`, `var_dump()`, `print_r()` and `var_export()` show `[redacted password]` or nothing, `serialize()` throws, and a stack trace shows only the object: the value is kept in a closure, not a property, and the constructor's parameter is `#[SensitiveParameter]`. An empty password throws `InvalidIdentity`, whose message does not repeat it. The rules for a password's length belong to the policy that sets it, not to the value.

## The default: Have I Been Pwned

`cbox-cms.contracts` binds the contract to `Cbox\Cms\Identity\BreachedPasswords\Adapter\HibpBreachedPasswords` of the identity module, unless the application names another class; see [Configuration](../../developers/configuration.md#contracts). It asks the range API of Have I Been Pwned's Pwned Passwords with k-anonymity:

- it computes the password's SHA-1 in the process, and only the first five hex digits leave it, in `GET https://api.pwnedpasswords.com/range/{prefix}`;
- it sends the header `Add-Padding: true`, so the service pads its answer with entries seen 0 times and the size of the answer does not give the prefix away; a padding entry never counts as a match;
- it matches the remaining 35 hex digits against every line of the answer in the process, each line in constant time;
- it goes through the [egress gateway](../../security/egress.md) under the host class `breached_passwords`, so the SSRF guard, the timeouts and the counters `cms.egress.requests` and `cms.egress.failures` apply.

A failure of the gateway, a status other than `200`, an empty answer or a line that is not 35 hex digits, a colon and a count throws `BreachedPasswordsUnavailable`. Every check adds 1 to the counter `cms.identity.breached_passwords.checks` with the attribute `cms.outcome`, which is `breached`, `clean` or `unavailable`, and nothing else.

## The fake: FakeBreachedPasswords

`Cbox\Cms\Testkit\Identity\FakeBreachedPasswords` knows the passwords it is constructed with or that `breach()` names, and throws `BreachedPasswordsUnavailable` after `goDown()` until `comeBack()`. It keeps a SHA-256 of each password, never the password. This example is in the `Unit` suite:

<!-- example: examples/Unit/Identity/BreachedPasswordsTest.php -->
```php
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
```

## Running the shared suite against an implementation

Every implementation runs the shared suite, the trait `Cbox\Cms\Testkit\Identity\BreachedPasswordsContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `harness(): BreachedPasswordsHarness`, which returns a fresh harness for each case. The harness has three methods: `breachedPasswords()` gives the implementation under test, `breach(Password $password)` makes a password known where it looks, and `goDown()` makes the service it asks stop answering. A harness for an implementation that asks a service fakes that service's transport and never asks the real one; the identity module's harness for `HibpBreachedPasswords` serves the range API, padding included, behind a fake of the egress gateway.

The cases cover a password nobody breached, a breached password next to clean ones, an exact match (case, one character more or less, Unicode), that a check changes nothing, and that a check that cannot be made fails closed with the retryable code and a message that holds neither the password nor its hash.
