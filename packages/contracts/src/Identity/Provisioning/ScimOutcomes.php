<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The rule ScimUserOutcome and ScimGroupOutcome share: a change has a key, and no change has none;
 * each change is named once.
 */
#[Internal]
final readonly class ScimOutcomes
{
    /**
     * @param  list<ScimChange>  $changes
     *
     * @throws InvalidIdentity
     */
    public static function check(array $changes, ?ScimIdempotencyKey $key): void
    {
        if (($changes === []) !== (! $key instanceof ScimIdempotencyKey)) {
            throw InvalidIdentity::signalValue('SCIM outcome', 'an outcome with changes and their key, or with neither');
        }

        if (count(array_unique(array_map(static fn (ScimChange $change): string => $change->value, $changes))) !== count($changes)) {
            throw InvalidIdentity::signalValue('SCIM outcome', 'an outcome that names each change once');
        }
    }
}
