<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeNodeReader;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * NodeReaderBehaviour against the fake the node action tests use, over the same world.
 */
final class FakeNodeReaderBehaviourTest extends TestCase
{
    use NodeReaderBehaviour;

    private ?FakeNodeReader $reader = null;

    #[Override]
    protected function nodeReader(): NodeReader
    {
        if ($this->reader instanceof FakeNodeReader) {
            return $this->reader;
        }

        $reader = new FakeNodeReader;
        $root = $reader->withNode(NodeId::fromString(self::ROOT), kind: NodeKind::Site);
        $node = $reader->withNode(NodeId::fromString(self::NODE), $root, version: self::NODE_VERSION);
        $archived = $reader->withNode(NodeId::fromString(self::ARCHIVED), $root, lifecycle: NodeLifecycle::Archived);
        $reader->withNode(NodeId::fromString(self::MOUNT), $root, kind: NodeKind::Mount);
        $reader->withNode(NodeId::fromString(self::FAR), kind: NodeKind::Site, reachable: false);

        $site = SiteId::fromString(self::SITE);
        $da = new Locale('da');
        $reader->withRoute($site, $da, new RequestPath('/'), $root->id);
        $reader->withRoute($site, $da, new RequestPath('/nyheder'), $node->id);

        $reader->withPlacement(PlacementId::fromString(self::LIVE), $node, new DateTimeImmutable(self::UNTIL));
        $reader->withPlacement(PlacementId::fromString(self::HIDDEN), $archived, shown: false);

        return $this->reader = $reader;
    }
}
