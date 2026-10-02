<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The answer to a SCIM call that creates, replaces or patches a group: the group as it is now, what
 * changed and the change's idempotency key. A call that asks for the state the group already has
 * changes nothing and has no key.
 */
#[Experimental]
final readonly class ScimGroupOutcome
{
    /**
     * @param  list<ScimChange>  $changes  in the order the commands ran; empty when nothing changed
     *
     * @throws InvalidIdentity when there are changes without a key, or a key without changes
     */
    public function __construct(public ScimGroupResource $group, public array $changes, public ?ScimIdempotencyKey $key)
    {
        ScimOutcomes::check($changes, $key);
    }

    public function changed(): bool
    {
        return $this->changes !== [];
    }
}
