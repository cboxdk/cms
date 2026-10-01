<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Cbox\Cms\Core\Tests\Seeding\Fakes\FakeSeedTargets;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SeedTargetsBehaviour against the fake the seeder's action tests use.
 */
final class FakeSeedTargetsBehaviourTest extends TestCase
{
    use SeedTargetsBehaviour;

    #[Override]
    protected function seedTargets(): SeedTargets
    {
        $root = $this->label(self::ROOT);

        return new FakeSeedTargets()
            ->withNode(NodeId::fromString(self::DEEP), new NodePath($root.'.'.$this->label(self::SECOND).'.'.$this->label(self::DEEP)))
            ->withNode(NodeId::fromString(self::FIRST), new NodePath($root.'.'.$this->label(self::FIRST)))
            ->withNode(NodeId::fromString(self::MOUNT), new NodePath($root.'.'.$this->label(self::MOUNT)), 'mount')
            ->withNode(NodeId::fromString(self::OUTSIDE), new NodePath($this->label(self::OUTSIDE)), 'site')
            ->withNode(NodeId::fromString(self::ROOT), new NodePath($root), 'site')
            ->withNode(NodeId::fromString(self::SECOND), new NodePath($root.'.'.$this->label(self::SECOND)))
            ->withEntry(EntryId::fromString(self::IN_DEEP), new NodePath($root.'.'.$this->label(self::SECOND).'.'.$this->label(self::DEEP)))
            ->withEntry(EntryId::fromString(self::IN_FIRST), new NodePath($root.'.'.$this->label(self::FIRST)))
            ->withEntry(EntryId::fromString(self::IN_OUTSIDE), new NodePath($this->label(self::OUTSIDE)));
    }

    #[Override]
    protected function actor(): ActorId
    {
        return ActorId::fromString('0192a0c0-0000-7000-8000-0000000048c1');
    }
}
