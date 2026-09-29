<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The fragment store and its cache index (PRD 8.12 point 1, 9.3; GUARDRAILS 2.3).
 *
 * It keeps rendered fragments by key with their dependency keys, build position and expiry, and
 * the reverse index from each dependency key to the fragments that depend on it. These are facts
 * about rendered output: they are true only while the output exists, and can be rebuilt by the
 * next render, so they live in the cache and never in Postgres (PRD 9.1). A store keeps fragments
 * and index together, so it never holds a fragment without its index entries.
 *
 * Time is the Clock's. A fragment is gone once the Clock reaches its validUntil: read() returns
 * null and fragmentsOf() leaves it out. A purge fence ends once the Clock reaches its fenceUntil.
 * The store's own copies expire at those instants too (TTL), so nothing outlives what it is for.
 *
 * - write() stores a fragment unless the purge fence refuses it, as one atomic step: the fence
 *   check, replacing what the key held, and updating the reverse index. A fragment is refused
 *   when any of its dependencies has a live fence at or above the fragment's build position; the
 *   read it was built from may not have seen the purged change. A write whose validUntil is not
 *   after the Clock's time throws InvalidCacheValue and stores nothing.
 * - read() returns the live fragment of a key, or null.
 * - fragmentsOf() returns the keys of the live fragments that depend on a dependency key, sorted.
 * - purge() writes the fence `purged:{key} = position` until fenceUntil, then removes every
 *   fragment that depends on the key, and takes each out of the index of its other dependencies.
 *   It returns the keys of the live fragments it removed, sorted. A fence never moves down: a
 *   purge of a key whose live fence is higher keeps the higher position, and the later of the two
 *   ends. A purge whose fenceUntil is not after the Clock's time throws InvalidCacheValue.
 */
#[Experimental]
interface FragmentStore
{
    public function write(Fragment $fragment): FragmentWriteOutcome;

    public function read(FragmentKey $key): ?Fragment;

    /**
     * @return list<FragmentKey> sorted by value
     */
    public function fragmentsOf(DependencyKey $key): array;

    /**
     * @return list<FragmentKey> the live fragments removed, sorted by value
     */
    public function purge(FragmentPurge $purge): array;
}
