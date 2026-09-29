<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Cache\FragmentWriteOutcome;
use Cbox\Cms\Contracts\Cache\InvalidCacheValue;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;

/**
 * The in-memory fake of FragmentStore (GUARDRAILS 2.3).
 *
 * It keeps fragments, the reverse index and the purge fences in memory and reads the time from its
 * Clock, a FakeClock by default, so a test moves the clock past a fragment's validUntil or a
 * fence's fenceUntil. What has expired stays in memory and is treated as gone, as a real store's
 * copies expire. It runs the shared suite FragmentStoreContract, as every real store does.
 */
#[Experimental]
final class FakeFragmentStore implements FragmentStore
{
    /** @var array<string, Fragment> by fragment key */
    private array $fragments = [];

    /** @var array<string, array<string, true>> dependency key to the fragment keys it lists */
    private array $index = [];

    /** @var array<string, FragmentPurge> by dependency key: the fence, with its highest position */
    private array $fences = [];

    public function __construct(private readonly Clock $clock = new FakeClock) {}

    public function write(Fragment $fragment): FragmentWriteOutcome
    {
        $now = $this->clock->now();

        if ($fragment->validUntil <= $now) {
            throw InvalidCacheValue::fragmentExpired($fragment->key, $fragment->validUntil, $now);
        }

        foreach ($fragment->dependencies as $dependency) {
            $fence = $this->liveFence($dependency, $now);

            if ($fence instanceof FragmentPurge && ! $fence->position->isBelow($fragment->builtAt)) {
                return new FragmentFenced($fragment->key, $dependency, $fence->position, $fragment->builtAt);
            }
        }

        $this->forget($fragment->key);
        $this->fragments[$fragment->key->value] = $fragment;

        foreach ($fragment->dependencies as $dependency) {
            $this->index[$dependency->toString()][$fragment->key->value] = true;
        }

        return new FragmentStored($fragment);
    }

    public function read(FragmentKey $key): ?Fragment
    {
        $fragment = $this->fragments[$key->value] ?? null;

        return $fragment !== null && $fragment->validUntil > $this->clock->now() ? $fragment : null;
    }

    public function fragmentsOf(DependencyKey $key): array
    {
        $keys = [];

        foreach (array_keys($this->index[$key->toString()] ?? []) as $fragmentKey) {
            if ($this->read(new FragmentKey((string) $fragmentKey)) instanceof Fragment) {
                $keys[] = (string) $fragmentKey;
            }
        }

        return $this->sorted($keys);
    }

    public function purge(FragmentPurge $purge): array
    {
        $now = $this->clock->now();

        if ($purge->fenceUntil <= $now) {
            throw InvalidCacheValue::fenceEnded($purge->key, $purge->fenceUntil, $now);
        }

        $fence = $this->liveFence($purge->key, $now);

        $this->fences[$purge->key->toString()] = $fence instanceof FragmentPurge ? new FragmentPurge(
            $purge->key,
            $purge->position->isBelow($fence->position) ? $fence->position : $purge->position,
            max($purge->fenceUntil, $fence->fenceUntil),
        ) : $purge;

        $removed = [];

        foreach (array_keys($this->index[$purge->key->toString()] ?? []) as $fragmentKey) {
            $key = new FragmentKey((string) $fragmentKey);

            if ($this->read($key) instanceof Fragment) {
                $removed[] = $key->value;
            }

            $this->forget($key);
        }

        unset($this->index[$purge->key->toString()]);

        return $this->sorted($removed);
    }

    /**
     * Removes the fragment the key holds, with its entries in the reverse index.
     */
    private function forget(FragmentKey $key): void
    {
        $old = $this->fragments[$key->value] ?? null;

        if ($old === null) {
            return;
        }

        foreach ($old->dependencies as $dependency) {
            unset($this->index[$dependency->toString()][$key->value]);
        }

        unset($this->fragments[$key->value]);
    }

    private function liveFence(DependencyKey $key, DateTimeImmutable $now): ?FragmentPurge
    {
        $fence = $this->fences[$key->toString()] ?? null;

        return $fence !== null && $fence->fenceUntil > $now ? $fence : null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<FragmentKey>
     */
    private function sorted(array $keys): array
    {
        sort($keys, SORT_STRING);

        return array_map(static fn (string $key): FragmentKey => new FragmentKey($key), $keys);
    }
}
