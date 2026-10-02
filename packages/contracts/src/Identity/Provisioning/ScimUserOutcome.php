<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The answer to a SCIM call that creates, replaces or patches a user: the user as it is now, what
 * changed and the change's idempotency key. A call that asks for the state the user already has
 * changes nothing and has no key, so a repeated request has one effect, and deactivating a
 * deactivated actor does nothing.
 */
#[Experimental]
final readonly class ScimUserOutcome
{
    /**
     * @param  list<ScimChange>  $changes  in the order the commands ran; empty when nothing changed
     *
     * @throws InvalidIdentity when there are changes without a key, or a key without changes
     */
    public function __construct(public ScimUserResource $user, public array $changes, public ?ScimIdempotencyKey $key)
    {
        ScimOutcomes::check($changes, $key);
    }

    public function changed(): bool
    {
        return $this->changes !== [];
    }
}
