<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Login;

use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Testkit\Login\FakeIssuerResolver;
use Cbox\Cms\Testkit\Tests\Login\Planted\AcceptsAnotherTenant;
use Cbox\Cms\Testkit\Tests\Login\Planted\AcceptsMissingTenantClaim;
use Cbox\Cms\Testkit\Tests\Login\Planted\PlantedResolverSuite;

/*
 * The shared suite IssuerResolverContract catches a resolver that loosens the tenant pin (PRD
 * 5.16): one that admits another tenant of the issuer, and one that admits a token without the
 * tenant claim. Each planted resolver fails exactly the cases that check what it loosens, and the
 * fake fails none.
 */

it('passes every case against the fake', function (): void {
    $suite = new PlantedResolverSuite(static fn (IssuerPin ...$pins): FakeIssuerResolver => new FakeIssuerResolver(...$pins));

    expect($suite->failing())->toBe([]);
});

it('fails a resolver that admits a token of another tenant', function (): void {
    $suite = new PlantedResolverSuite(static fn (IssuerPin ...$pins): AcceptsAnotherTenant => new AcceptsAnotherTenant(...$pins));

    expect($suite->failing())->toBe([
        'a_token_of_another_tenant_is_refused',
        'two_connections_to_one_issuer_each_admit_only_their_own_tenant',
    ]);
});

it('fails a resolver that admits a token without the tenant claim', function (): void {
    $suite = new PlantedResolverSuite(static fn (IssuerPin ...$pins): AcceptsMissingTenantClaim => new AcceptsMissingTenantClaim(...$pins));

    expect($suite->failing())->toBe([
        'a_token_without_the_tenant_claim_is_refused',
        'a_tenant_in_another_claim_than_the_pinned_one_is_not_read',
    ]);
});
