<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Fragments;

use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Fragments\Domain\SubtreeNodes;
use Cbox\Cms\Core\Tests\Fragments\Fakes\FakeSubtreeNodes;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SubtreeNodesBehaviour against the fake the invalidation tests use, over the same world.
 */
final class FakeSubtreeNodesBehaviourTest extends TestCase
{
    use SubtreeNodesBehaviour;

    #[Override]
    protected function subtreeNodes(): SubtreeNodes
    {
        $reader = new FakeSubtreeNodes;
        $root = $reader->withNode(NodeId::fromString(self::ROOT));
        $first = $reader->withNode(NodeId::fromString(self::FIRST), $root);
        $reader->withNode(NodeId::fromString(self::SECOND), $root);
        $reader->withNode(NodeId::fromString(self::THIRD), $first);
        $reader->withNode(NodeId::fromString(self::FAR));

        return $reader;
    }
}
