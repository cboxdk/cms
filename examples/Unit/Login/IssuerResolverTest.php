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
