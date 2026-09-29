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
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for FragmentStore (GUARDRAILS 2.3 and 9). The fake and every real
 * store run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * new, empty store that reads the time from $clock:
 *
 *     final class FakeFragmentStoreContractTest extends TestCase
 *     {
 *         use FragmentStoreContract;
 *
 *         protected function fragmentStore(Clock $clock): FragmentStore
 *         {
 *             return new FakeFragmentStore($clock);
 *         }
 *     }
 *
 * The cases cover a write and its read back, expiry on the Clock, the reverse index as fragments
 * are written, rewritten and purged, and the purge fence of PRD 8.12 point 1: a fragment built at
 * or below a dependency's purge position is refused while the fence lives, one built above it is
 * stored, and a fence never moves down.
 */
#[Experimental]
trait FragmentStoreContract
{
    /**
     * A new, empty store whose expiry reads the time from $clock.
     */
    abstract protected function fragmentStore(Clock $clock): FragmentStore;

    #[Test]
    public function a_written_fragment_is_read_back_equal(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        $keys = $this->dependencyKeys(3);
        $fragment = $this->fragment($clock, 'page:/news', [$keys[2], $keys[0], $keys[1], $keys[0]], '100', "<h1>News</h1>\n\x00bytes");

        $outcome = $store->write($fragment);

        Assert::assertInstanceOf(FragmentStored::class, $outcome);
        Assert::assertEquals($fragment, $outcome->fragment);
        $read = $store->read(new FragmentKey('page:/news'));
        $this->assertSameFragment($fragment, $read);
        Assert::assertSame(
            [$keys[0]->toString(), $keys[1]->toString(), $keys[2]->toString()],
            $this->strings($read instanceof Fragment ? $read->dependencies : []),
        );
    }

    #[Test]
    public function an_unknown_key_reads_as_null_and_an_unused_dependency_lists_nothing(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$used, $unused] = $this->dependencyKeys(2);
        $store->write($this->fragment($clock, 'page:/a', [$used], '100'));

        Assert::assertNull($store->read(new FragmentKey('page:/b')));
        Assert::assertSame([], $store->fragmentsOf($unused));
    }

    #[Test]
    public function a_fragment_without_dependencies_is_stored_and_read_back(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        $fragment = $this->fragment($clock, 'page:/static', [], '1');

        Assert::assertInstanceOf(FragmentStored::class, $store->write($fragment));
        $this->assertSameFragment($fragment, $store->read(new FragmentKey('page:/static')));
    }

    #[Test]
    public function a_fragment_is_gone_at_its_valid_until_on_the_time_source(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $fragment = $this->fragment($clock, 'page:/a', [$key], '100', seconds: 60);
        $store->write($fragment);

        $clock->set($fragment->validUntil->modify('-1 microsecond'));
        $this->assertSameFragment($fragment, $store->read(new FragmentKey('page:/a')));
        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($key)));

        $clock->set($fragment->validUntil);
        Assert::assertNull($store->read(new FragmentKey('page:/a')));
        Assert::assertSame([], $store->fragmentsOf($key));
    }

    #[Test]
    public function a_write_that_is_already_expired_is_refused_and_stores_nothing(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $expired = new Fragment(new FragmentKey('page:/a'), 'old', [$key], new CommitPosition('100'), $clock->now());

        try {
            $store->write($expired);
            Assert::fail('The store wrote a fragment whose validUntil is the Clock\'s time.');
        } catch (InvalidCacheValue $refused) {
            Assert::assertStringContainsString('page:/a', $refused->getMessage());
        }

        Assert::assertNull($store->read(new FragmentKey('page:/a')));
        Assert::assertSame([], $store->fragmentsOf($key));
    }

    #[Test]
    public function the_reverse_index_lists_every_live_fragment_of_a_dependency_sorted(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$article, $section, $site] = $this->dependencyKeys(3);
        $store->write($this->fragment($clock, 'page:/news/b', [$article, $section], '100'));
        $store->write($this->fragment($clock, 'page:/news/a', [$article, $site], '100'));
        $store->write($this->fragment($clock, 'page:/news', [$section], '100'));

        Assert::assertSame(['page:/news/a', 'page:/news/b'], $this->values($store->fragmentsOf($article)));
        Assert::assertSame(['page:/news', 'page:/news/b'], $this->values($store->fragmentsOf($section)));
        Assert::assertSame(['page:/news/a'], $this->values($store->fragmentsOf($site)));
    }

    #[Test]
    public function rewriting_a_fragment_replaces_it_and_moves_it_in_the_reverse_index(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$before, $kept, $after] = $this->dependencyKeys(3);
        $store->write($this->fragment($clock, 'page:/a', [$before, $kept], '100', 'first'));
        $second = $this->fragment($clock, 'page:/a', [$kept, $after], '101', 'second', seconds: 30);

        Assert::assertInstanceOf(FragmentStored::class, $store->write($second));

        $this->assertSameFragment($second, $store->read(new FragmentKey('page:/a')));
        Assert::assertSame([], $store->fragmentsOf($before));
        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($kept)));
        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($after)));
    }

    #[Test]
    public function a_purge_removes_every_fragment_of_the_key_from_the_store_and_from_the_index_of_their_other_keys(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$purged, $other, $untouched] = $this->dependencyKeys(3);
        $store->write($this->fragment($clock, 'page:/b', [$purged, $other], '100'));
        $store->write($this->fragment($clock, 'page:/a', [$purged], '100'));
        $kept = $this->fragment($clock, 'page:/c', [$other, $untouched], '100');
        $store->write($kept);

        $removed = $store->purge($this->purge($clock, $purged, '150'));

        Assert::assertSame(['page:/a', 'page:/b'], $this->values($removed));
        Assert::assertNull($store->read(new FragmentKey('page:/a')));
        Assert::assertNull($store->read(new FragmentKey('page:/b')));
        Assert::assertSame([], $store->fragmentsOf($purged));
        Assert::assertSame(['page:/c'], $this->values($store->fragmentsOf($other)));
        Assert::assertSame(['page:/c'], $this->values($store->fragmentsOf($untouched)));
        $this->assertSameFragment($kept, $store->read(new FragmentKey('page:/c')));
    }

    #[Test]
    public function a_purge_returns_only_the_live_fragments_it_removed(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $store->write($this->fragment($clock, 'page:/short', [$key], '100', seconds: 10));
        $store->write($this->fragment($clock, 'page:/long', [$key], '100', seconds: 600));

        $clock->advance(new DateInterval('PT10S'));

        Assert::assertSame(['page:/long'], $this->values($store->purge($this->purge($clock, $key, '150'))));
        Assert::assertSame([], $store->fragmentsOf($key));
    }

    #[Test]
    public function a_purge_of_a_key_without_fragments_removes_nothing_and_still_fences(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);

        Assert::assertSame([], $store->purge($this->purge($clock, $key, '150')));
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '150')), 'page:/a', $key, '150', '150');
    }

    #[Test]
    public function the_fence_refuses_a_fragment_built_at_or_below_the_purge_position_and_stores_one_built_above_it(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $store->purge($this->purge($clock, $key, '1000'));

        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '999')), 'page:/a', $key, '1000', '999');
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '1000')), 'page:/a', $key, '1000', '1000');
        Assert::assertNull($store->read(new FragmentKey('page:/a')));
        Assert::assertSame([], $store->fragmentsOf($key));

        $above = $this->fragment($clock, 'page:/a', [$key], '1001');
        Assert::assertInstanceOf(FragmentStored::class, $store->write($above));
        $this->assertSameFragment($above, $store->read(new FragmentKey('page:/a')));
        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($key)));
    }

    #[Test]
    public function the_fence_compares_positions_by_their_numeric_value_up_to_the_largest_xid8(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$short, $long] = $this->dependencyKeys(2);
        $store->purge($this->purge($clock, $short, '99'));
        $store->purge($this->purge($clock, $long, '18446744073709551614'));

        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/a', [$short], '100')));
        $this->assertFenced($store->write($this->fragment($clock, 'page:/b', [$short], '9')), 'page:/b', $short, '99', '9');
        $this->assertFenced(
            $store->write($this->fragment($clock, 'page:/c', [$long], '18446744073709551614')),
            'page:/c',
            $long,
            '18446744073709551614',
            '18446744073709551614',
        );
        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/c', [$long], CommitPosition::MAX)));
    }

    #[Test]
    public function a_refused_write_keeps_what_the_key_held_before(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$held, $purged] = $this->dependencyKeys(2);
        $before = $this->fragment($clock, 'page:/a', [$held], '100', 'before');
        $store->write($before);
        $store->purge($this->purge($clock, $purged, '200'));

        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$purged], '150', 'after')), 'page:/a', $purged, '200', '150');

        $this->assertSameFragment($before, $store->read(new FragmentKey('page:/a')));
        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($held)));
        Assert::assertSame([], $store->fragmentsOf($purged));
    }

    #[Test]
    public function a_fragment_is_refused_when_any_dependency_fences_it_and_the_first_fencing_key_is_named(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        $keys = $this->dependencyKeys(3);
        $sorted = $keys;
        usort($sorted, static fn (DependencyKey $a, DependencyKey $b): int => strcmp($a->toString(), $b->toString()));
        $store->purge($this->purge($clock, $sorted[2], '300'));
        $store->purge($this->purge($clock, $sorted[1], '200'));
        $store->purge($this->purge($clock, $sorted[0], '100'));

        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', $keys, '250')), 'page:/a', $sorted[2], '300', '250');
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', $keys, '150')), 'page:/a', $sorted[1], '200', '150');
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', $keys, '100')), 'page:/a', $sorted[0], '100', '100');
        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/a', $keys, '301')));
    }

    #[Test]
    public function a_fence_on_another_key_does_not_refuse_a_fragment(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$purged, $other] = $this->dependencyKeys(2);
        $store->purge($this->purge($clock, $purged, '500'));

        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/a', [$other], '1')));
    }

    #[Test]
    public function a_fence_never_moves_down_and_keeps_the_later_end(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $store->purge($this->purge($clock, $key, '200', seconds: 60));
        $store->purge($this->purge($clock, $key, '100', seconds: 30));

        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '150')), 'page:/a', $key, '200', '150');

        $clock->advance(new DateInterval('PT45S'));
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '200')), 'page:/a', $key, '200', '200');

        $store->purge($this->purge($clock, $key, '300', seconds: 5));
        $clock->advance(new DateInterval('PT10S'));
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '250')), 'page:/a', $key, '300', '250');
    }

    #[Test]
    public function a_fence_ends_at_its_fence_until_on_the_time_source(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $purge = $this->purge($clock, $key, '1000', seconds: 30);
        $store->purge($purge);

        $clock->set($purge->fenceUntil->modify('-1 microsecond'));
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '10')), 'page:/a', $key, '1000', '10');

        $clock->set($purge->fenceUntil);
        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/a', [$key], '10')));

        $store->purge($this->purge($clock, $key, '5'));
        $this->assertFenced($store->write($this->fragment($clock, 'page:/a', [$key], '5')), 'page:/a', $key, '5', '5');
        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/a', [$key], '6')));
    }

    #[Test]
    public function a_purge_whose_fence_has_already_ended_is_refused_and_changes_nothing(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        [$key] = $this->dependencyKeys(1);
        $store->write($this->fragment($clock, 'page:/a', [$key], '100'));

        try {
            $store->purge(new FragmentPurge($key, new CommitPosition('200'), $clock->now()));
            Assert::fail('The store took a purge whose fence ends at the Clock\'s time.');
        } catch (InvalidCacheValue $refused) {
            Assert::assertStringContainsString($key->toString(), $refused->getMessage());
        }

        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($key)));
        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/b', [$key], '1')));
    }

    #[Test]
    public function node_and_entry_keys_of_the_same_uuid_are_separate_dependencies(): void
    {
        $clock = new FakeClock;
        $store = $this->fragmentStore($clock);
        $uuid = new FakeIdGenerator(seed: 7)->next();
        $entry = DependencyKey::entry(new EntryId($uuid));
        $node = DependencyKey::node(new NodeId($uuid));
        $store->write($this->fragment($clock, 'page:/a', [$entry], '100'));
        $store->write($this->fragment($clock, 'page:/b', [$node], '100'));

        Assert::assertSame(['page:/b'], $this->values($store->purge($this->purge($clock, $node, '150'))));
        Assert::assertSame(['page:/a'], $this->values($store->fragmentsOf($entry)));
        Assert::assertInstanceOf(FragmentStored::class, $store->write($this->fragment($clock, 'page:/c', [$entry], '100')));
    }

    /**
     * $count distinct entry keys, from a seeded generator.
     *
     * @return list<DependencyKey>
     */
    protected function dependencyKeys(int $count): array
    {
        $ids = new FakeIdGenerator(seed: 34);
        $keys = [];

        for ($i = 0; $i < $count; $i++) {
            $keys[] = DependencyKey::entry(new EntryId($ids->next()));
        }

        return $keys;
    }

    /**
     * A fragment valid for $seconds from the Clock's time.
     *
     * @param  list<DependencyKey>  $dependencies
     */
    protected function fragment(Clock $clock, string $key, array $dependencies, string $builtAt, string $body = 'body', int $seconds = 300): Fragment
    {
        return new Fragment(
            new FragmentKey($key),
            $body,
            $dependencies,
            new CommitPosition($builtAt),
            $clock->now()->add(new DateInterval(sprintf('PT%dS', $seconds))),
        );
    }

    /**
     * A purge whose fence lasts $seconds from the Clock's time.
     */
    protected function purge(Clock $clock, DependencyKey $key, string $position, int $seconds = 120): FragmentPurge
    {
        return new FragmentPurge($key, new CommitPosition($position), $clock->now()->add(new DateInterval(sprintf('PT%dS', $seconds))));
    }

    private function assertSameFragment(Fragment $expected, ?Fragment $actual): void
    {
        Assert::assertNotNull($actual, sprintf('The store has no fragment for "%s".', $expected->key->value));
        Assert::assertSame($expected->key->value, $actual->key->value);
        Assert::assertSame($expected->body, $actual->body);
        Assert::assertSame($this->strings($expected->dependencies), $this->strings($actual->dependencies));
        Assert::assertSame($expected->builtAt->value, $actual->builtAt->value);
        Assert::assertSame($this->instant($expected->validUntil), $this->instant($actual->validUntil));
        Assert::assertSame('UTC', $actual->validUntil->getTimezone()->getName());
    }

    private function assertFenced(FragmentWriteOutcome $outcome, string $key, DependencyKey $dependency, string $purgedAt, string $builtAt): void
    {
        Assert::assertInstanceOf(FragmentFenced::class, $outcome, sprintf('The store did not refuse "%s" built at %s.', $key, $builtAt));
        Assert::assertSame($key, $outcome->key->value);
        Assert::assertSame($dependency->toString(), $outcome->dependency->toString());
        Assert::assertSame($purgedAt, $outcome->purgedAt->value);
        Assert::assertSame($builtAt, $outcome->builtAt->value);
    }

    /**
     * @param  list<DependencyKey>  $keys
     * @return list<string>
     */
    private function strings(array $keys): array
    {
        return array_map(static fn (DependencyKey $key): string => $key->toString(), $keys);
    }

    /**
     * @param  list<FragmentKey>  $keys
     * @return list<string>
     */
    private function values(array $keys): array
    {
        return array_map(static fn (FragmentKey $key): string => $key->value, $keys);
    }

    private function instant(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.uP');
    }
}
