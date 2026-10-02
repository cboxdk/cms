<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use SensitiveParameter;

/**
 * An account the identity provider behind a FakeLoginConnection knows: the identifier and secret a
 * login form takes for it, the claims of the token the provider issues for it, and the methods,
 * context and groups the provider reports.
 */
#[Experimental]
final readonly class FakeLoginAccount
{
    /**
     * @param  list<AuthenticationMethod>  $amr
     * @param  list<IdpGroup>|null  $groups
     */
    public function __construct(
        public string $identifier,
        #[SensitiveParameter] public string $secret,
        public TokenClaims $claims,
        public array $amr = [],
        public ?AuthenticationContext $acr = null,
        public ?array $groups = null,
    ) {}
}
