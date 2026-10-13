<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Content;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Tests\Entries\ListedContent;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Contracts\Foundation\Application;

/**
 * The content list on real Postgres behind the panel itself (PRD 5.4, 13.4): AccessWorld, whose
 * administrator may run entry.create and reaches the site's root, over partitions that cover the
 * clock's next two days, and the entries of ListedContent: the event homed on the root and the
 * articles on the section below it, so the administrator reaches every one.
 */
final readonly class ContentWorld
{
    public AccessWorld $access;

    /**
     * @param  int  $seed  the seed of the ids AccessWorld writes
     * @param  bool  $fixtureBuild  whether AccessWorld binds a FixtureBuild; a browser test runs against the real build
     */
    public function __construct(Application $app, int $seed = 97, bool $fixtureBuild = true)
    {
        $this->access = new AccessWorld($app, seed: $seed, fixtureBuild: $fixtureBuild, site: 'content');
        $app->make(PartitionFixtures::class)->coverClock($app->make(Clock::class), new DateInterval('P2D'));
        ListedContent::seed($app->make(TypeCatalog::class), $this->access->root->toString(), $this->access->section->toString());
    }

    public function cleanUp(): void
    {
        $this->access->cleanUp();
    }
}
