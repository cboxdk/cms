<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReadModels;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\ReadModels\Fakes\FakeReadModelStore;
use Cbox\Cms\Tests\TestCase;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * ReadModelStoreBehaviour against the fake the action tests use, and what the fake adds: it
 * records the plans and the ranges it rebuilt with their contexts, and runs what a test gave for a
 * range before it rebuilds it.
 */
final class FakeReadModelStoreBehaviourTest extends TestCase
{
    use ReadModelStoreBehaviour;

    private ?FakeReadModelStore $store = null;

    #[Override]
    protected function readModelStore(): ReadModelStore
    {
        return $this->fake();
    }

    #[Override]
    protected function rebuildAccess(): AccessContext
    {
        return AccessContext::anonymous();
    }

    #[Override]
    protected function addEntries(TypeDefinition $type, int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $this->fake()->add($type, RebuildWorld::entry($number));
        }
    }

    #[Override]
    protected function atOtherVersion(TypeDefinition $type, int $number): void
    {
        $this->fake()->add($type, RebuildWorld::entry($number), $type->version + 1);
    }

    #[Test]
    public function it_records_the_plans_and_the_ranges_it_rebuilt_and_runs_what_comes_before_a_range(): void
    {
        $type = EntryWorld::type(EntryWorld::MEASUREMENT);
        $range = new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(1));
        $ran = 0;
        $store = $this->fake()->add($type, RebuildWorld::entry(1))->before($range, static function () use (&$ran): void {
            $ran++;
        });

        $store->plan($type, AccessContext::anonymous(), 3);
        $store->rebuild($type, AccessContext::anonymous(), $range);
        $store->rebuild($type, AccessContext::anonymous(), $range);

        self::assertEquals([[$type, 3, AccessContext::anonymous()]], $store->planned);
        self::assertSame([$range->chunk()->value, $range->chunk()->value], $store->rebuiltChunks());
        self::assertSame(1, $ran);
    }

    #[Test]
    public function it_refuses_a_chunk_size_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fake()->plan(EntryWorld::type(EntryWorld::MEASUREMENT), AccessContext::anonymous(), 0);
    }

    private function fake(): FakeReadModelStore
    {
        return $this->store ??= new FakeReadModelStore;
    }
}
