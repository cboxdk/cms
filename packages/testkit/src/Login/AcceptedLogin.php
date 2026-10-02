<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\Subject;

/**
 * A response the identity provider accepts, from LoginConnectionHarness::accepted(), with what the
 * verified assertion must then say: the subject, the methods, the context and the groups.
 */
#[Experimental]
final readonly class AcceptedLogin
{
    /**
     * @param  list<AuthenticationMethod>  $amr
     * @param  list<IdpGroup>|null  $groups
     */
    public function __construct(
        public LoginResponse $response,
        public Subject $subject,
        public array $amr = [],
        public ?AuthenticationContext $acr = null,
        public ?array $groups = null,
    ) {}
}
