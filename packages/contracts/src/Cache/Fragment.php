<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A rendered fragment and what it was built from (PRD 8.12 point 1, 9.3): its key, its bytes,
 * the content keys it depends on, the commit position its read was at and the instant it stops
 * being valid.
 *
 * The dependencies are kept sorted by their string form, each once; at most MAX_DEPENDENCIES, the
 * cap on surrogate keys per response (PRD 9.6). $builtAt is the position of the read the fragment
 * was built from, the xmin of its snapshot: the read saw every changeset below it (CommitPosition).
 * $validUntil is kept in UTC with its microseconds; a fragment is gone from the store once the
 * Clock reaches it, and the store's own copy expires then too.
 */
#[Experimental]
final readonly class Fragment
{
    /** The most dependency keys one fragment carries (PRD 9.6). */
    public const int MAX_DEPENDENCIES = 50;

    /** @var list<DependencyKey> sorted by toString(), each once */
    public array $dependencies;

    public DateTimeImmutable $validUntil;

    /**
     * @param  list<DependencyKey>  $dependencies
     */
    public function __construct(
        public FragmentKey $key,
        public string $body,
        array $dependencies,
        public CommitPosition $builtAt,
        DateTimeImmutable $validUntil,
    ) {
        $byKey = [];

        foreach ($dependencies as $dependency) {
            $byKey[$dependency->toString()] = $dependency;
        }

        if (count($byKey) > self::MAX_DEPENDENCIES) {
            throw InvalidCacheValue::tooManyDependencies(count($byKey));
        }

        ksort($byKey, SORT_STRING);
        $this->dependencies = array_values($byKey);
        $this->validUntil = $validUntil->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether the fragment depends on $key.
     */
    public function dependsOn(DependencyKey $key): bool
    {
        return array_any($this->dependencies, fn (DependencyKey $dependency): bool => $dependency->equals($key));
    }
}
