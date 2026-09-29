<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\CdnUnavailable;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for CdnDriver (GUARDRAILS 2.3 and 9). The fake and every real driver
 * run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new driver whose edge has taken no request yet:
 *
 *     final class FakeCdnDriverContractTest extends TestCase
 *     {
 *         use CdnDriverContract;
 *
 *         protected function cdn(): CdnDriverHarness
 *         {
 *             return new FakeCdnDriver;
 *         }
 *     }
 *
 * The cases cover a purge in one request and split over several, the mode the edge applies to a
 * soft and a hard purge (PRD 8.12 point 3), that a purge can be repeated, and that a driver whose
 * edge refuses throws CdnUnavailable and purges again once the edge is back.
 */
#[Experimental]
trait CdnDriverContract
{
    /**
     * A harness for a new driver whose edge has taken no request.
     */
    abstract protected function cdn(): CdnDriverHarness;

    #[Test]
    public function a_driver_takes_at_least_one_key_per_request(): void
    {
        Assert::assertGreaterThanOrEqual(1, $this->cdn()->driver()->maxKeysPerRequest());
    }

    #[Test]
    public function a_purge_that_fits_one_request_purges_every_key_in_one_request(): void
    {
        $cdn = $this->cdn();
        $count = min(3, $cdn->driver()->maxKeysPerRequest());
        $keys = $this->surrogateKeys($count);

        $result = $cdn->driver()->purge(new CdnPurge($keys, PurgeMode::Hard));

        Assert::assertSame(1, $result->requests);
        Assert::assertSame($this->strings($keys), $result->applied->keyStrings());
        Assert::assertSame(PurgeMode::Hard, $result->applied->mode);
        Assert::assertCount(1, $cdn->requests());
        Assert::assertSame($this->strings($keys), $cdn->requests()[0]->keyStrings());
        Assert::assertSame(PurgeMode::Hard, $cdn->requests()[0]->mode);
    }

    #[Test]
    public function a_purge_with_more_keys_than_one_request_takes_is_split_in_order(): void
    {
        $cdn = $this->cdn();
        $max = $cdn->driver()->maxKeysPerRequest();
        $keys = $this->surrogateKeys(2 * $max + 1);

        $result = $cdn->driver()->purge(new CdnPurge($keys, PurgeMode::Hard));

        Assert::assertSame(3, $result->requests);
        Assert::assertCount(3, $cdn->requests());
        Assert::assertSame($this->strings($keys), $result->applied->keyStrings());

        $sent = [];

        foreach ($cdn->requests() as $request) {
            Assert::assertLessThanOrEqual($max, count($request->keys));
            Assert::assertSame(PurgeMode::Hard, $request->mode);
            array_push($sent, ...$request->keyStrings());
        }

        Assert::assertSame($this->strings($keys), $sent);
    }

    #[Test]
    public function a_hard_purge_is_applied_hard(): void
    {
        $cdn = $this->cdn();

        $result = $cdn->driver()->purge(new CdnPurge($this->surrogateKeys(1), PurgeMode::Hard));

        Assert::assertSame(PurgeMode::Hard, $result->applied->mode);
        Assert::assertSame(PurgeMode::Hard, $cdn->requests()[0]->mode);
    }

    #[Test]
    public function a_soft_purge_is_applied_soft_only_by_a_driver_that_supports_it(): void
    {
        $cdn = $this->cdn();
        $expected = $cdn->driver()->supportsSoftPurge() ? PurgeMode::Soft : PurgeMode::Hard;

        $result = $cdn->driver()->purge(new CdnPurge($this->surrogateKeys(1), PurgeMode::Soft));

        Assert::assertSame($expected, $result->applied->mode);
        Assert::assertSame($expected, $cdn->requests()[0]->mode);
    }

    #[Test]
    public function purging_the_same_keys_again_purges_them_again(): void
    {
        $cdn = $this->cdn();
        $keys = $this->surrogateKeys(1);

        $cdn->driver()->purge(new CdnPurge($keys, PurgeMode::Hard));
        $again = $cdn->driver()->purge(new CdnPurge($keys, PurgeMode::Hard));

        Assert::assertSame(1, $again->requests);
        Assert::assertCount(2, $cdn->requests());
        Assert::assertSame($this->strings($keys), $cdn->requests()[1]->keyStrings());
    }

    #[Test]
    public function an_edge_that_refuses_makes_the_purge_throw_and_the_purge_succeeds_once_it_is_back(): void
    {
        $cdn = $this->cdn();
        $keys = $this->surrogateKeys(2);
        $cdn->interrupt();

        try {
            $cdn->driver()->purge(new CdnPurge($keys, PurgeMode::Hard));
            Assert::fail('The driver did not throw CdnUnavailable while the edge refused.');
        } catch (CdnUnavailable) {
            Assert::assertSame([], $cdn->requests());
        }

        $cdn->restore();
        $result = $cdn->driver()->purge(new CdnPurge($keys, PurgeMode::Hard));

        Assert::assertSame($this->strings($keys), $result->applied->keyStrings());
        Assert::assertSame($this->strings($keys), $cdn->requests()[0]->keyStrings());
    }

    /**
     * $count distinct surrogate keys, entry and node keys in turn, from a seeded generator.
     *
     * @return list<DependencyKey>
     */
    protected function surrogateKeys(int $count): array
    {
        $ids = new FakeIdGenerator(seed: 8);
        $keys = [];

        for ($i = 0; $i < $count; $i++) {
            $keys[] = $i % 2 === 0 ? DependencyKey::entry(new EntryId($ids->next())) : DependencyKey::node(new NodeId($ids->next()));
        }

        return $keys;
    }

    /**
     * @param  list<DependencyKey>  $keys
     * @return list<string>
     */
    private function strings(array $keys): array
    {
        return array_map(static fn (DependencyKey $key): string => $key->toString(), $keys);
    }
}
