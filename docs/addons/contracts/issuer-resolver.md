---
title: Issuer resolver
weight: 43
description: "The IssuerResolver contract: pin a login connection to its issuer and, for an issuer with several tenants, to its tenant read from the token's claims, the refusals, the testkit's FakeIssuerResolver and the shared suite IssuerResolverContract with its harness."
---

# Issuer resolver

<!-- extension-point: Cbox\Cms\Contracts\Identity\Login\IssuerResolver -->
<!-- extension-point: Cbox\Cms\Testkit\Login\IssuerResolverHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Login\IssuerResolverContract -->

A [login connection](login-connection.md) trusts a token only from the issuer it is pinned to and, for an issuer with several tenants, only for its tenant (PRD 5.16). Microsoft Entra ID serves many directories, and Google is one issuer for every account, personal or of any Workspace domain, so the issuer alone does not say whose account it is. `Cbox\Cms\Contracts\Identity\Login\IssuerResolver` holds the pins and decides. It is `#[Experimental]`: the real resolver, which reads the pins from the environment's configuration, comes with the OpenID Connect implementation.

## The contract

- `pin(ConnectionId $connection): IssuerPin` gives the connection's pin: its `Issuer` and, for an issuer with several tenants, a `TenantPin` of the `TenantClaim` that carries the tenant, such as `tid` for Entra ID or `hd` for Google, and the `TenantId` it must carry.
- `admit(ConnectionId $connection, TokenClaims $claims): IdpIdentity` gives the IdP identity of a verified token's claims.

Both throw `UnknownConnection` for a connection without a pin, a fault of the configuration. An `Issuer` is compared exactly, as OpenID Connect compares it, so a trailing slash makes another issuer.

`TokenClaims` holds the issuer, the subject and the token's other claims whose value is text. A connection builds it only after it has checked the token's signature, audience, expiry and nonce, and only from the token. The tenant is therefore read from the token's claim, never from a parameter of the request: Google's `hd` parameter in the authorization request is only a hint, and the `hd` claim of the ID token is what counts.

`admit()` decides through `IssuerPin::admit()`, so every resolver applies the same rules, in this order, refusing with `LoginRefused`:

| Reason | Code | When |
|---|---|---|
| `IssuerMismatch` | `login_issuer_mismatch` | the token is from another issuer, whatever tenant it names |
| `TenantClaimMissing` | `login_tenant_claim_missing` | the pin has a tenant, and the token does not carry its claim, or carries it empty; a tenant in another claim is not read |
| `TenantMismatch` | `login_tenant_mismatch` | the pin has a tenant, and the claim names another, compared exactly |

The codes are in the [error reference](../../reference/errors.md). Two connections may share an issuer and pin two tenants; each admits only its own.

## The fake: FakeIssuerResolver

`Cbox\Cms\Testkit\Login\FakeIssuerResolver` holds the pins it is constructed with, one per connection, and is its own harness. This example is in the `Unit` suite:

<!-- example: examples/Unit/Login/IssuerResolverTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\TenantClaim;
use Cbox\Cms\Contracts\Identity\Login\TenantId;
use Cbox\Cms\Contracts\Identity\Login\TenantPin;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Testkit\Login\FakeIssuerResolver;

// A Google connection for one Workspace domain. Google is one issuer for every account, so the
// connection is pinned to the domain in the hd claim of the ID token. A personal account carries
// no hd claim, and an account of another domain carries another; both are refused, whatever the
// hd parameter of the request said (PRD 5.16).

it('admits only accounts of the pinned Workspace domain', function (): void {
    $google = new ConnectionId('google-example');
    $issuer = new Issuer('https://accounts.google.com');
    $resolver = new FakeIssuerResolver(new IssuerPin($google, $issuer, new TenantPin(new TenantClaim('hd'), new TenantId('example.org'))));
    $refusal = static function (TokenClaims $claims) use ($resolver, $google): ?LoginErrorCode {
        try {
            $resolver->admit($google, $claims);

            return null;
        } catch (LoginRefused $refused) {
            return $refused->reason;
        }
    };

    $identity = $resolver->admit($google, new TokenClaims($issuer, new Subject('1001'), ['hd' => 'example.org']));

    expect($identity->connection->value)->toBe('google-example')
        ->and($refusal(new TokenClaims($issuer, new Subject('1002'))))->toBe(LoginErrorCode::TenantClaimMissing)
        ->and($refusal(new TokenClaims($issuer, new Subject('1003'), ['hd' => 'example.net'])))->toBe(LoginErrorCode::TenantMismatch)
        ->and($refusal(new TokenClaims(new Issuer('https://evil.example'), new Subject('1001'), ['hd' => 'example.org'])))->toBe(LoginErrorCode::IssuerMismatch);
});
```

## Running the shared suite against a resolver

Every resolver runs the shared suite, the trait `Cbox\Cms\Testkit\Login\IssuerResolverContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `issuers(): IssuerResolverHarness`, and the harness has one, `resolver(IssuerPin ...$pins): IssuerResolver`, which configures a resolver with exactly those pins. A harness for a real resolver writes them where it reads its connections.

The cases cover the pin of a connection, an unknown connection, a token of the pinned issuer and of another, and for a connection pinned to a tenant: a token of that tenant, of another, without the claim, with it empty, with the tenant in another claim, another issuer with the right tenant, and two connections to one issuer pinned to two tenants. The testkit holds the suite to its purpose: `packages/testkit/tests/Login/PlantedResolverTest.php` runs it against a resolver that admits another tenant and one that admits a token without the tenant claim, and each fails.
