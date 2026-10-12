<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Fragments;

use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Fragments\Domain\SubtreeNodes;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SubtreeNodes of the kernel does (PRD 5.8, 9.4), held against the fake the invalidation
 * tests use and PostgresSubtreeNodes, over one world the implementation's test writes: the root
 * ROOT with the sections FIRST and SECOND below it, the page THIRD below FIRST, and the root FAR of
 * another tree. The ids are UUIDv7s whose last digits order them FIRST, SECOND, THIRD, so id order
 * is not tree order.
 */
trait SubtreeNodesBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000000f1';

    public const string FIRST = '0192a0c0-0000-7000-8000-0000000000f2';

    public const string SECOND = '0192a0c0-0000-7000-8000-0000000000f3';

    public const string THIRD = '0192a0c0-0000-7000-8000-0000000000f4';

    public const string FAR = '0192a0c0-0000-7000-8000-0000000000f5';

    public const string NOWHERE = '0192a0c0-0000-7000-8000-0000000000f6';

    abstract protected function subtreeNodes(): SubtreeNodes;

    /**
     * @param  list<NodeId>  $nodes
     * @return list<string>
     */
    private static function ids(array $nodes): array
    {
        return array_map(static fn (NodeId $node): string => $node->toString(), $nodes);
    }

    #[Test]
    public function it_reads_the_node_and_its_descendants_in_id_order_and_nothing_outside_the_subtree(): void
    {
        $reader = $this->subtreeNodes();

        Assert::assertSame([self::ROOT, self::FIRST, self::SECOND, self::THIRD], self::ids($reader->below(NodeId::fromString(self::ROOT), null, 10)));
        Assert::assertSame([self::FIRST, self::THIRD], self::ids($reader->below(NodeId::fromString(self::FIRST), null, 10)));
        Assert::assertSame([self::THIRD], self::ids($reader->below(NodeId::fromString(self::THIRD), null, 10)));
        Assert::assertSame([self::FAR], self::ids($reader->below(NodeId::fromString(self::FAR), null, 10)));
    }

    #[Test]
    public function it_pages_by_id_after_the_last_id_given_and_gives_nothing_for_a_node_that_does_not_exist(): void
    {
        $reader = $this->subtreeNodes();
        $root = NodeId::fromString(self::ROOT);

        Assert::assertSame([self::ROOT, self::FIRST], self::ids($reader->below($root, null, 2)));
        Assert::assertSame([self::SECOND, self::THIRD], self::ids($reader->below($root, NodeId::fromString(self::FIRST), 2)));
        Assert::assertSame([], self::ids($reader->below($root, NodeId::fromString(self::THIRD), 2)));
        Assert::assertSame([], self::ids($reader->below(NodeId::fromString(self::NOWHERE), null, 2)));
    }
}
