<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Content;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Tests\Entries\ListedContent;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;

/**
 * The content list on real Postgres behind the panel itself (PRD 5.4, 13.4): AccessWorld, whose
 * administrator may run entry.create and reaches the site's root, over partitions that cover the
 * clock's next two days, and the entries of ListedContent: the event homed on the root and the
 * articles on the section below it, so the administrator reaches every one; with publishing, the
 * administrator also holds a role on the root with the commands of the editor's publish panel.
 */
final readonly class ContentWorld
{
    public AccessWorld $access;

    /** The commands of the editor's publish panel, which a world with publishing grants the administrator on the site's root. */
    public const array PUBLISHING = ['placement.create', 'placement.set_window', 'entry.publish', 'entry.unpublish', 'variant.release'];

    /**
     * @param  int  $seed  the seed of the ids AccessWorld writes
     * @param  bool  $fixtureBuild  whether AccessWorld binds a FixtureBuild; a browser test runs against the real build
     * @param  bool  $publishing  whether the administrator also holds a role with the commands of PUBLISHING on the site's root
     */
    public function __construct(Application $app, int $seed = 97, bool $fixtureBuild = true, bool $publishing = false)
    {
        $this->access = new AccessWorld($app, seed: $seed, fixtureBuild: $fixtureBuild, site: 'content');
        $app->make(PartitionFixtures::class)->coverClock($app->make(Clock::class), new DateInterval('P2D'));
        ListedContent::seed($app->make(TypeCatalog::class), $this->access->root->toString(), $this->access->section->toString());

        if ($publishing) {
            $clock = $app->make(Clock::class);
            $access = new PostgresAccessFixtures($app->make(DatabaseManager::class), $clock, new FakeIdGenerator(seed: $seed + 1000, clock: $clock));
            $role = $access->role('publishing', ClassificationAccess::Internal, array_map(static fn (string $name): CommandName => new CommandName($name), self::PUBLISHING));
            $access->grant($this->access->admin, $role, $this->access->root);
        }
    }

    public function cleanUp(): void
    {
        $this->access->cleanUp();
    }
}
