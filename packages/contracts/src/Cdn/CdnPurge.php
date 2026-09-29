<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;

/**
 * One purge for a CdnDriver: the surrogate keys to purge and how (PRD 8.12 points 3 and 5). The
 * caller gathers the keys of a changeset into one purge per driver; the driver splits it into
 * requests of at most its maxKeysPerRequest().
 *
 * The keys are kept in the order given, each once, and there is at least one.
 */
#[Experimental]
final readonly class CdnPurge
{
    /** @var non-empty-list<DependencyKey> in the order given, each once */
    public array $keys;

    /**
     * @param  list<DependencyKey>  $keys
     */
    public function __construct(array $keys, public PurgeMode $mode)
    {
        $byKey = [];

        foreach ($keys as $key) {
            $byKey[$key->toString()] ??= $key;
        }

        $unique = array_values($byKey);

        if ($unique === []) {
            throw InvalidCdnPurge::noKeys();
        }

        $this->keys = $unique;
    }

    /**
     * The keys in their string form, as the edge gets them.
     *
     * @return non-empty-list<string>
     */
    public function keyStrings(): array
    {
        return array_map(static fn (DependencyKey $key): string => $key->toString(), $this->keys);
    }
}
